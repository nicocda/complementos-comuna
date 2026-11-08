<?php

namespace Tests\Feature;

use App\Enums\ProblemKind;
use App\Enums\RepairStatus;
use App\Enums\RepairUrgency;
use App\Enums\UnitType;
use App\Exceptions\InvalidRepairTransition;
use App\Models\Driver;
use App\Models\RepairRequest;
use App\Models\Unit;
use App\Models\User;
use App\Notifications\RepairRequestStatusChanged;
use App\Services\RepairWorkflow;
use App\Support\Permissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RepairWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private RepairWorkflow $workflow;

    private User $supervisor;

    private User $superior;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedRoles();
        Notification::fake();

        $this->workflow = app(RepairWorkflow::class);
        $this->supervisor = $this->userWithRole(Permissions::ROLE_SUPERVISOR);
        $this->superior = $this->userWithRole(Permissions::ROLE_SUPERIOR);
    }

    public function test_driver_reports_with_correlative_numbers_and_unit_snapshot(): void
    {
        [$user, $driver] = $this->driverUser();
        $unit = Unit::factory()->create(['internal_number' => 43, 'type' => UnitType::Tractor, 'name' => 'John Deere 5065E']);

        $first = $this->workflow->report($user, $this->noticeData($unit));
        $second = $this->workflow->report($user, $this->noticeData($unit));

        $this->assertSame(1, $first->number);
        $this->assertSame(2, $second->number);
        $this->assertSame('00001', $first->formattedNumber());
        $this->assertSame(RepairStatus::Reported, $first->status);
        $this->assertTrue($first->driver->is($driver));
        $this->assertSame('Tractor John Deere 5065E · Interno N° 43', $first->unit_label);
        $this->assertSame(1, $first->events()->count());
    }

    public function test_driver_always_reports_as_themselves(): void
    {
        [$user, $ownDriver] = $this->driverUser();
        $otherDriver = Driver::factory()->create();

        $request = $this->workflow->report($user, $this->noticeData(Unit::factory()->create(), $otherDriver));

        $this->assertTrue($request->driver->is($ownDriver));
    }

    public function test_driver_user_without_linked_driver_cannot_report(): void
    {
        $user = $this->userWithRole(Permissions::ROLE_DRIVER);

        $this->expectException(ValidationException::class);

        $this->workflow->report($user, $this->noticeData(Unit::factory()->create()));
    }

    public function test_supervisor_reports_on_behalf_of_a_driver(): void
    {
        $driver = Driver::factory()->create();

        $request = $this->workflow->report($this->supervisor, $this->noticeData(Unit::factory()->create(), $driver));

        $this->assertTrue($request->driver->is($driver));
        $this->assertTrue($request->reporter->is($this->supervisor));
    }

    public function test_cannot_report_on_out_of_service_unit(): void
    {
        $this->expectException(ValidationException::class);

        $this->workflow->report($this->supervisor, $this->noticeData(Unit::factory()->outOfService()->create(), Driver::factory()->create()));
    }

    public function test_cannot_report_with_future_detection_date(): void
    {
        $this->expectException(ValidationException::class);

        $this->workflow->report($this->supervisor, [
            ...$this->noticeData(Unit::factory()->create(), Driver::factory()->create()),
            'detected_on' => today()->addDay()->toDateString(),
        ]);
    }

    public function test_cannot_report_for_inactive_driver(): void
    {
        $this->expectException(ValidationException::class);

        $this->workflow->report($this->supervisor, $this->noticeData(Unit::factory()->create(), Driver::factory()->inactive()->create()));
    }

    public function test_new_notice_notifies_supervisors_but_not_the_actor_nor_inactive_users(): void
    {
        [$user] = $this->driverUser();
        $inactiveSupervisor = $this->userWithRole(Permissions::ROLE_SUPERVISOR, ['is_active' => false]);

        $this->workflow->report($user, $this->noticeData(Unit::factory()->create()));

        Notification::assertSentTo($this->supervisor, RepairRequestStatusChanged::class);
        Notification::assertNotSentTo($inactiveSupervisor, RepairRequestStatusChanged::class);
        Notification::assertNotSentTo($user, RepairRequestStatusChanged::class);
        Notification::assertNotSentTo($this->superior, RepairRequestStatusChanged::class);
    }

    public function test_full_happy_path(): void
    {
        [$user] = $this->driverUser();
        $request = $this->workflow->report($user, $this->noticeData(Unit::factory()->create()));

        $request = $this->workflow->submitAssessment($this->supervisor, $request, [
            'urgency' => RepairUrgency::High->value,
            'inspection_notes' => 'Se inspeccionó, pierde aceite.',
            'estimated_parts_or_service' => 'Retén de bomba',
            'estimated_cost' => '150000',
        ]);
        $this->assertSame(RepairStatus::PendingApproval, $request->status);
        $this->assertSame(RepairUrgency::High, $request->urgency);
        $this->assertSame('150000.00', $request->estimated_cost);
        Notification::assertSentTo(
            $this->superior,
            RepairRequestStatusChanged::class,
            fn (RepairRequestStatusChanged $n) => $n->status === RepairStatus::PendingApproval,
        );

        $request = $this->workflow->approve($this->superior, $request, 'Proceder');
        $this->assertSame(RepairStatus::Approved, $request->status);
        $this->assertTrue($request->decider->is($this->superior));
        $this->assertNotNull($request->decided_at);
        Notification::assertSentTo(
            $user,
            RepairRequestStatusChanged::class,
            fn (RepairRequestStatusChanged $n) => $n->status === RepairStatus::Approved,
        );

        $request = $this->workflow->startRepair($this->supervisor, $request, 'Taller Pérez');
        $this->assertSame(RepairStatus::InRepair, $request->status);
        $this->assertSame('Taller Pérez', $request->provider);

        $request = $this->workflow->complete($this->supervisor, $request, [
            'provider' => 'Taller Pérez',
            'work_performed' => 'Cambio de retén',
            'final_cost' => '175000.50',
            'completed_on' => today()->toDateString(),
        ]);
        $this->assertSame(RepairStatus::Completed, $request->status);
        $this->assertSame('175000.50', $request->final_cost);

        $this->assertSame(
            [RepairStatus::Reported, RepairStatus::PendingApproval, RepairStatus::Approved, RepairStatus::InRepair, RepairStatus::Completed],
            $request->events()->reorder('id')->get()->pluck('to_status')->all(),
        );
    }

    public function test_driver_with_account_is_notified_when_supervisor_reported_for_them(): void
    {
        [$driverUser, $driver] = $this->driverUser();
        $request = $this->workflow->report($this->supervisor, $this->noticeData(Unit::factory()->create(), $driver));
        $request = $this->workflow->submitAssessment($this->supervisor, $request, ['urgency' => 'baja']);

        $this->workflow->approve($this->superior, $request);

        Notification::assertSentTo(
            $driverUser,
            RepairRequestStatusChanged::class,
            fn (RepairRequestStatusChanged $n) => $n->status === RepairStatus::Approved,
        );
    }

    public function test_driver_cannot_assess(): void
    {
        [$user] = $this->driverUser();
        $request = $this->workflow->report($user, $this->noticeData(Unit::factory()->create()));

        $this->expectException(AuthorizationException::class);

        $this->workflow->submitAssessment($user, $request, ['urgency' => 'alta']);
    }

    public function test_supervisor_cannot_approve(): void
    {
        $request = $this->pendingApproval();

        $this->expectException(AuthorizationException::class);

        $this->workflow->approve($this->supervisor, $request);
    }

    public function test_superior_cannot_approve_before_the_report(): void
    {
        $request = RepairRequest::factory()->create();

        $this->expectException(InvalidRepairTransition::class);

        $this->workflow->approve($this->superior, $request);
    }

    public function test_cannot_approve_twice(): void
    {
        $request = $this->workflow->approve($this->superior, $this->pendingApproval());

        $this->expectException(InvalidRepairTransition::class);

        $this->workflow->approve($this->superior, $request);
    }

    public function test_stale_model_cannot_skip_transition_rules(): void
    {
        $request = $this->pendingApproval();
        $stale = RepairRequest::query()->find($request->getKey());
        $this->workflow->reject($this->superior, $request, 'Sin presupuesto');

        // The in-memory copy still says "pending approval", but the workflow re-reads the row.
        $this->expectException(InvalidRepairTransition::class);

        $this->workflow->approve($this->superior, $stale);
    }

    public function test_rejection_requires_a_reason(): void
    {
        $this->expectException(ValidationException::class);

        $this->workflow->reject($this->superior, $this->pendingApproval(), '   ');
    }

    public function test_superior_can_send_back_for_more_information(): void
    {
        $request = $this->workflow->returnForReview($this->superior, $this->pendingApproval(), 'Falta presupuesto');

        $this->assertSame(RepairStatus::Reported, $request->status);
        $this->assertSame('Falta presupuesto', $request->events()->first()->comment);
        Notification::assertSentTo(
            $this->supervisor,
            RepairRequestStatusChanged::class,
            fn (RepairRequestStatusChanged $n) => $n->returnedForReview,
        );

        $request = $this->workflow->submitAssessment($this->supervisor, $request, ['urgency' => 'media', 'estimated_cost' => 1000]);
        $this->assertSame(RepairStatus::PendingApproval, $request->status);
    }

    public function test_complete_requires_provider_work_and_cost(): void
    {
        $request = $this->workflow->approve($this->superior, $this->pendingApproval());

        try {
            $this->workflow->complete($this->supervisor, $request, [
                'provider' => '',
                'work_performed' => 'Algo',
                'final_cost' => '10',
                'completed_on' => today()->toDateString(),
            ]);
            $this->fail('Expected validation error.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('provider', $e->errors());
        }

        $this->expectException(ValidationException::class);

        $this->workflow->complete($this->supervisor, $request, [
            'provider' => 'Taller',
            'work_performed' => 'Algo',
            'final_cost' => '-5',
            'completed_on' => today()->toDateString(),
        ]);
    }

    public function test_cancel_only_while_reported(): void
    {
        $request = RepairRequest::factory()->create();
        $request = $this->workflow->cancel($this->supervisor, $request, 'Duplicado');
        $this->assertSame(RepairStatus::Cancelled, $request->status);

        $this->expectException(InvalidRepairTransition::class);

        $this->workflow->cancel($this->supervisor, $this->pendingApproval(), 'Tarde');
    }

    public function test_inactive_user_cannot_act_even_with_role(): void
    {
        $inactive = $this->userWithRole(Permissions::ROLE_SUPERIOR, ['is_active' => false]);

        $this->expectException(AuthorizationException::class);

        $this->workflow->approve($inactive, $this->pendingApproval());
    }

    public function test_signed_form_path_must_be_inside_upload_folder(): void
    {
        $request = RepairRequest::factory()->create();

        foreach (['../../.env', '/etc/passwd', 'repair-requests/signed-forms/../../../.env', 'otra-carpeta/x.pdf'] as $path) {
            try {
                $this->workflow->attachSignedForm($this->supervisor, $request, $path);
                $this->fail("Path {$path} should have been rejected.");
            } catch (ValidationException) {
                $this->assertNull($request->fresh()->signed_form_path);
            }
        }

        $this->workflow->attachSignedForm($this->supervisor, $request, RepairWorkflow::SIGNED_FORMS_DIRECTORY.'/ok.pdf');
        $this->assertSame(RepairWorkflow::SIGNED_FORMS_DIRECTORY.'/ok.pdf', $request->fresh()->signed_form_path);
    }

    private function pendingApproval(): RepairRequest
    {
        $request = $this->workflow->report($this->supervisor, $this->noticeData(Unit::factory()->create(), Driver::factory()->create()));

        return $this->workflow->submitAssessment($this->supervisor, $request, ['urgency' => 'media']);
    }

    /**
     * @return array<string, mixed>
     */
    private function noticeData(Unit $unit, ?Driver $driver = null): array
    {
        return [
            'unit_id' => $unit->getKey(),
            'driver_id' => $driver?->getKey(),
            'problem_kind' => ProblemKind::PotentialFailure->value,
            'problem_description' => 'Ruido fuerte en la caja de cambios.',
            'detected_on' => today()->toDateString(),
        ];
    }
}
