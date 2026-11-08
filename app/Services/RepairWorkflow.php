<?php

namespace App\Services;

use App\Enums\ProblemKind;
use App\Enums\RepairStatus;
use App\Enums\RepairUrgency;
use App\Enums\UnitStatus;
use App\Exceptions\InvalidRepairTransition;
use App\Models\Driver;
use App\Models\RepairRequest;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\RepairRequestStatusChanged;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * The only place where repair requests are created or change status.
 *
 * Every transition checks the actor's permission and the current status,
 * writes an audit event and notifies the people who must act next.
 */
class RepairWorkflow
{
    public const SIGNED_FORMS_DIRECTORY = 'repair-requests/signed-forms';

    private const MAX_NUMBER_ATTEMPTS = 3;

    /**
     * Step 1: driver (or someone on their behalf) reports a breakdown or failure.
     *
     * @param  array{unit_id: int|string, driver_id?: int|string|null, problem_kind: ProblemKind|string, problem_description: string, detected_on: string|\DateTimeInterface}  $data
     */
    public function report(User $actor, array $data): RepairRequest
    {
        $this->authorize($actor, Permissions::REPAIRS_REPORT);

        $unit = Unit::query()->findOrFail($data['unit_id']);

        if ($unit->status === UnitStatus::OutOfService) {
            throw ValidationException::withMessages(['unit_id' => 'La unidad está dada de baja.']);
        }

        $driver = $this->resolveDriver($actor, $data['driver_id'] ?? null);
        $detectedOn = Carbon::parse($data['detected_on'])->startOfDay();

        if ($detectedOn->isAfter(today())) {
            throw ValidationException::withMessages(['detected_on' => 'La fecha de detección no puede ser futura.']);
        }

        $request = $this->createWithNextNumber(function (int $number) use ($actor, $unit, $driver, $data, $detectedOn) {
            $request = new RepairRequest;
            $request->forceFill([
                'number' => $number,
                'status' => RepairStatus::Reported,
                'unit_id' => $unit->getKey(),
                'unit_label' => $unit->label(),
                'driver_id' => $driver->getKey(),
                'reported_by' => $actor->getKey(),
                'problem_kind' => $data['problem_kind'] instanceof ProblemKind ? $data['problem_kind'] : ProblemKind::from($data['problem_kind']),
                'problem_description' => trim($data['problem_description']),
                'detected_on' => $detectedOn,
            ])->save();

            $this->recordEvent($request, $actor, null, RepairStatus::Reported, null);

            return $request;
        });

        $this->notify($request, $actor, $this->usersWith(Permissions::REPAIRS_ASSESS));

        return $request;
    }

    /**
     * Step 2 + 3: the supervisor completes the technical report and submits it to superiors.
     *
     * @param  array{inspection_notes?: string|null, urgency: RepairUrgency|string, estimated_parts_or_service?: string|null, estimated_cost?: float|int|string|null}  $data
     */
    public function submitAssessment(User $actor, RepairRequest $request, array $data): RepairRequest
    {
        $this->authorize($actor, Permissions::REPAIRS_ASSESS);

        return $this->transition($request, $actor, RepairStatus::PendingApproval, null, function (RepairRequest $request) use ($actor, $data) {
            $request->forceFill([
                'inspection_notes' => $this->nullableText($data['inspection_notes'] ?? null),
                'urgency' => $data['urgency'] instanceof RepairUrgency ? $data['urgency'] : RepairUrgency::from($data['urgency']),
                'estimated_parts_or_service' => $this->nullableText($data['estimated_parts_or_service'] ?? null),
                'estimated_cost' => $this->nullableMoney($data['estimated_cost'] ?? null, 'estimated_cost'),
                'assessed_by' => $actor->getKey(),
                'assessed_at' => now(),
            ]);
        }, fn () => $this->usersWith(Permissions::REPAIRS_APPROVE));
    }

    public function approve(User $actor, RepairRequest $request, ?string $notes = null): RepairRequest
    {
        return $this->decide($actor, $request, RepairStatus::Approved, $notes);
    }

    public function reject(User $actor, RepairRequest $request, string $notes): RepairRequest
    {
        $this->requireText($notes, 'decision_notes', 'Indicá el motivo del rechazo.');

        return $this->decide($actor, $request, RepairStatus::Rejected, $notes);
    }

    /**
     * Superior sends the request back to the supervisor asking for more information.
     */
    public function returnForReview(User $actor, RepairRequest $request, string $notes): RepairRequest
    {
        $this->authorize($actor, Permissions::REPAIRS_APPROVE);
        $this->requireText($notes, 'decision_notes', 'Indicá qué información falta.');

        return $this->transition(
            $request,
            $actor,
            RepairStatus::Reported,
            $notes,
            null,
            fn (RepairRequest $request) => $this->usersWith(Permissions::REPAIRS_ASSESS),
        );
    }

    /**
     * Step 4a: purchase/repair management started.
     */
    public function startRepair(User $actor, RepairRequest $request, ?string $provider = null): RepairRequest
    {
        $this->authorize($actor, Permissions::REPAIRS_TRACK);

        return $this->transition($request, $actor, RepairStatus::InRepair, null, function (RepairRequest $request) use ($provider) {
            if ($this->nullableText($provider) !== null) {
                $request->provider = $this->nullableText($provider);
            }
        }, fn (RepairRequest $request) => $this->interestedParties($request));
    }

    /**
     * Step 4b: repair finished, with provider and final cost.
     *
     * @param  array{provider?: string|null, work_performed?: string|null, final_cost?: float|int|string|null, completed_on: string|\DateTimeInterface}  $data
     */
    public function complete(User $actor, RepairRequest $request, array $data): RepairRequest
    {
        $this->authorize($actor, Permissions::REPAIRS_TRACK);

        $provider = $this->requireText($data['provider'] ?? null, 'provider', 'Indicá el proveedor o mecánico responsable.');
        $workPerformed = $this->requireText($data['work_performed'] ?? null, 'work_performed', 'Describí la reparación realizada.');
        $finalCost = $this->nullableMoney($data['final_cost'] ?? null, 'final_cost')
            ?? throw ValidationException::withMessages(['final_cost' => 'Indicá el costo final.']);
        $completedOn = Carbon::parse($data['completed_on'])->startOfDay();

        if ($completedOn->isAfter(today())) {
            throw ValidationException::withMessages(['completed_on' => 'La fecha de finalización no puede ser futura.']);
        }

        return $this->transition($request, $actor, RepairStatus::Completed, null, function (RepairRequest $request) use ($provider, $workPerformed, $finalCost, $completedOn) {
            $request->forceFill([
                'provider' => $provider,
                'work_performed' => $workPerformed,
                'final_cost' => $finalCost,
                'completed_on' => $completedOn,
            ]);
        }, fn (RepairRequest $request) => $this->interestedParties($request));
    }

    public function cancel(User $actor, RepairRequest $request, string $reason): RepairRequest
    {
        $this->authorize($actor, Permissions::REPAIRS_ASSESS);
        $this->requireText($reason, 'reason', 'Indicá el motivo de la anulación.');

        return $this->transition(
            $request,
            $actor,
            RepairStatus::Cancelled,
            $reason,
            null,
            fn (RepairRequest $request) => $this->reporterAndDriver($request),
        );
    }

    /**
     * Stores the path of the scanned notice signed by the driver.
     */
    public function attachSignedForm(User $actor, RepairRequest $request, string $path): RepairRequest
    {
        $this->authorize($actor, Permissions::REPAIRS_ASSESS);

        if ($request->status === RepairStatus::Cancelled) {
            throw new InvalidRepairTransition('No se puede adjuntar el formulario a un aviso anulado.');
        }

        // The download route trusts this value, so only accept files inside the uploads folder.
        if (! str_starts_with($path, self::SIGNED_FORMS_DIRECTORY.'/') || str_contains($path, '..') || str_contains($path, '\\')) {
            throw ValidationException::withMessages(['signed_form' => 'Archivo inválido.']);
        }

        $request->forceFill(['signed_form_path' => $path])->save();

        return $request;
    }

    private function decide(User $actor, RepairRequest $request, RepairStatus $target, ?string $notes): RepairRequest
    {
        $this->authorize($actor, Permissions::REPAIRS_APPROVE);

        return $this->transition($request, $actor, $target, $notes, function (RepairRequest $request) use ($actor, $notes) {
            $request->forceFill([
                'decided_by' => $actor->getKey(),
                'decided_at' => now(),
                'decision_notes' => $this->nullableText($notes),
            ]);
        }, fn (RepairRequest $request) => $this->interestedParties($request));
    }

    /**
     * @param  (callable(RepairRequest): void)|null  $mutate
     * @param  callable(RepairRequest): Collection<int, User>  $recipients
     */
    private function transition(
        RepairRequest $request,
        User $actor,
        RepairStatus $target,
        ?string $comment,
        ?callable $mutate,
        callable $recipients,
    ): RepairRequest {
        $request = DB::transaction(function () use ($request, $actor, $target, $comment, $mutate) {
            /** @var RepairRequest $locked */
            $locked = RepairRequest::query()->lockForUpdate()->findOrFail($request->getKey());
            $from = $locked->status;

            if (! $from->canTransitionTo($target)) {
                throw InvalidRepairTransition::between($from, $target);
            }

            if ($mutate !== null) {
                $mutate($locked);
            }

            $locked->status = $target;
            $locked->save();

            $this->recordEvent($locked, $actor, $from, $target, $comment);

            return $locked;
        });

        $this->notify($request, $actor, $recipients($request));

        return $request;
    }

    /**
     * @param  callable(int): RepairRequest  $create
     */
    private function createWithNextNumber(callable $create): RepairRequest
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return DB::transaction(function () use ($create) {
                    $last = RepairRequest::query()->lockForUpdate()->max('number');

                    return $create(((int) $last) + 1);
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($attempt >= self::MAX_NUMBER_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    private function recordEvent(RepairRequest $request, User $actor, ?RepairStatus $from, RepairStatus $to, ?string $comment): void
    {
        $request->events()->make()->forceFill([
            'user_id' => $actor->getKey(),
            'from_status' => $from,
            'to_status' => $to,
            'comment' => $this->nullableText($comment),
            'created_at' => now(),
        ])->save();
    }

    private function resolveDriver(User $actor, int|string|null $driverId): Driver
    {
        // Users who can only report (drivers) always report as themselves.
        if (! $actor->can(Permissions::REPAIRS_VIEW_ALL)) {
            $own = $actor->driver;

            if ($own === null || ! $own->is_active) {
                throw ValidationException::withMessages(['driver_id' => 'Tu usuario no está vinculado a un conductor activo.']);
            }

            return $own;
        }

        if ($driverId === null || $driverId === '') {
            throw ValidationException::withMessages(['driver_id' => 'Seleccioná el conductor.']);
        }

        $driver = Driver::query()->find($driverId);

        if ($driver === null || ! $driver->is_active) {
            throw ValidationException::withMessages(['driver_id' => 'El conductor no existe o está inactivo.']);
        }

        return $driver;
    }

    /**
     * @param  Collection<int, User>  $recipients
     */
    private function notify(RepairRequest $request, User $actor, Collection $recipients): void
    {
        $recipients = $recipients
            ->filter(fn (User $user) => $user->is_active && ! $user->is($actor))
            ->unique(fn (User $user) => $user->getKey());

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new RepairRequestStatusChanged($request, $actor));
        }
    }

    /**
     * Supervisors, trackers, the person who reported it and the driver (if they have an account).
     *
     * @return Collection<int, User>
     */
    private function interestedParties(RepairRequest $request): Collection
    {
        return $this->usersWith(Permissions::REPAIRS_ASSESS)
            ->merge($this->usersWith(Permissions::REPAIRS_TRACK))
            ->merge($this->reporterAndDriver($request));
    }

    /**
     * The person who loaded the notice and the driver's own account, when different.
     *
     * @return Collection<int, User>
     */
    private function reporterAndDriver(RepairRequest $request): Collection
    {
        $driverUserId = Driver::query()->whereKey($request->driver_id)->value('user_id');

        return User::query()->whereKey(array_filter([$request->reported_by, $driverUserId]))->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function usersWith(string $permission): Collection
    {
        return User::permission($permission)->where('is_active', true)->get();
    }

    private function authorize(User $actor, string $permission): void
    {
        if (! $actor->is_active || ! $actor->can($permission)) {
            throw new AuthorizationException('No tenés permiso para realizar esta acción.');
        }
    }

    /**
     * @return non-empty-string the trimmed value
     */
    private function requireText(?string $value, string $field, string $message): string
    {
        return $this->nullableText($value) ?? throw ValidationException::withMessages([$field => $message]);
    }

    private function nullableText(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function nullableMoney(float|int|string|null $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value) || (float) $value < 0) {
            throw ValidationException::withMessages([$field => 'El importe debe ser un número positivo.']);
        }

        return number_format((float) $value, 2, '.', '');
    }
}
