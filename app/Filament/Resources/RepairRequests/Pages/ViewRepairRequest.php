<?php

namespace App\Filament\Resources\RepairRequests\Pages;

use App\Enums\RepairUrgency;
use App\Exceptions\InvalidRepairTransition;
use App\Filament\Resources\RepairRequests\RepairRequestResource;
use App\Models\RepairRequest;
use App\Models\User;
use App\Services\RepairWorkflow;
use App\Support\WhatsAppLink;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ViewRepairRequest extends ViewRecord
{
    protected static string $resource = RepairRequestResource::class;

    public function getTitle(): string
    {
        return "Solicitud N° {$this->repairRequest()->formattedNumber()}";
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->assessAction(),
            $this->approveAction(),
            $this->rejectAction(),
            $this->returnAction(),
            $this->startRepairAction(),
            $this->completeAction(),
            ActionGroup::make([
                Action::make('print')
                    ->label('Imprimir aviso')
                    ->icon(Heroicon::OutlinedPrinter)
                    ->url(fn () => route('repair-requests.print', $this->repairRequest()))
                    ->openUrlInNewTab(),
                Action::make('whatsapp')
                    ->label('Enviar por WhatsApp')
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->url(fn () => WhatsAppLink::forRepairRequest($this->repairRequest()))
                    ->openUrlInNewTab(),
                $this->uploadSignedFormAction(),
                $this->cancelAction(),
            ])->label('Más')->button()->color('gray'),
        ];
    }

    private function assessAction(): Action
    {
        return Action::make('assess')
            ->label('Elaborar informe y elevar')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->authorize('assess')
            ->modalHeading('Informe técnico administrativo')
            ->modalDescription('Al confirmar, el informe se eleva a los superiores para su aprobación.')
            ->modalSubmitActionLabel('Elevar a superiores')
            ->fillForm(fn () => [
                'inspection_notes' => $this->repairRequest()->inspection_notes,
                'urgency' => $this->repairRequest()->urgency,
                'estimated_parts_or_service' => $this->repairRequest()->estimated_parts_or_service,
                'estimated_cost' => $this->repairRequest()->estimated_cost,
            ])
            ->schema([
                Select::make('urgency')
                    ->label('Urgencia estimada')
                    ->options(RepairUrgency::class)
                    ->required(),
                Textarea::make('inspection_notes')
                    ->label('Observaciones propias (si se inspeccionó la unidad)')
                    ->maxLength(5000)
                    ->rows(4),
                Textarea::make('estimated_parts_or_service')
                    ->label('Estimación preliminar de repuestos o servicio a contratar')
                    ->maxLength(5000)
                    ->rows(3),
                TextInput::make('estimated_cost')
                    ->label('Costo estimado ($)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(9999999999),
            ])
            ->action(fn (array $data) => $this->runWorkflow(
                fn (RepairWorkflow $workflow, User $user) => $workflow->submitAssessment($user, $this->repairRequest(), $data),
                'Informe elevado a superiores',
            ));
    }

    private function approveAction(): Action
    {
        return Action::make('approve')
            ->label('Autorizar')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->authorize('decide')
            ->modalHeading('Autorizar reparación')
            ->modalDescription('Autoriza la reparación y la compra de repuestos o contratación del servicio.')
            ->schema([
                Textarea::make('decision_notes')->label('Observaciones (opcional)')->maxLength(2000),
            ])
            ->action(fn (array $data) => $this->runWorkflow(
                fn (RepairWorkflow $workflow, User $user) => $workflow->approve($user, $this->repairRequest(), $data['decision_notes'] ?? null),
                'Reparación autorizada',
            ));
    }

    private function rejectAction(): Action
    {
        return Action::make('reject')
            ->label('Rechazar')
            ->icon(Heroicon::OutlinedXCircle)
            ->color('danger')
            ->authorize('decide')
            ->modalHeading('Rechazar reparación')
            ->schema([
                Textarea::make('decision_notes')->label('Motivo del rechazo')->required()->maxLength(2000),
            ])
            ->action(fn (array $data) => $this->runWorkflow(
                fn (RepairWorkflow $workflow, User $user) => $workflow->reject($user, $this->repairRequest(), $data['decision_notes']),
                'Reparación rechazada',
            ));
    }

    private function returnAction(): Action
    {
        return Action::make('return')
            ->label('Pedir más información')
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->color('gray')
            ->authorize('decide')
            ->modalHeading('Devolver al encargado')
            ->schema([
                Textarea::make('decision_notes')->label('¿Qué información falta?')->required()->maxLength(2000),
            ])
            ->action(fn (array $data) => $this->runWorkflow(
                fn (RepairWorkflow $workflow, User $user) => $workflow->returnForReview($user, $this->repairRequest(), $data['decision_notes']),
                'Solicitud devuelta al encargado',
            ));
    }

    private function startRepairAction(): Action
    {
        return Action::make('startRepair')
            ->label('Iniciar reparación')
            ->icon(Heroicon::OutlinedPlayCircle)
            ->authorize('startRepair')
            ->modalHeading('Iniciar gestión de compra y reparación')
            ->schema([
                TextInput::make('provider')->label('Proveedor o mecánico (si ya se sabe)')->maxLength(150),
            ])
            ->action(fn (array $data) => $this->runWorkflow(
                fn (RepairWorkflow $workflow, User $user) => $workflow->startRepair($user, $this->repairRequest(), $data['provider'] ?? null),
                'Reparación en curso',
            ));
    }

    private function completeAction(): Action
    {
        return Action::make('complete')
            ->label('Registrar finalización')
            ->icon(Heroicon::OutlinedCheckBadge)
            ->color('success')
            ->authorize('complete')
            ->modalHeading('Reparación realizada')
            ->fillForm(fn () => [
                'provider' => $this->repairRequest()->provider,
                'completed_on' => today(),
            ])
            ->schema([
                TextInput::make('provider')->label('Proveedor o mecánico responsable')->required()->maxLength(150),
                Textarea::make('work_performed')->label('Reparación realizada')->required()->maxLength(5000)->rows(4),
                TextInput::make('final_cost')->label('Costo final ($)')->numeric()->minValue(0)->maxValue(9999999999)->required(),
                DatePicker::make('completed_on')->label('Fecha de finalización')->maxDate(today())->required(),
            ])
            ->action(fn (array $data) => $this->runWorkflow(
                fn (RepairWorkflow $workflow, User $user) => $workflow->complete($user, $this->repairRequest(), $data),
                'Reparación finalizada',
            ));
    }

    private function cancelAction(): Action
    {
        return Action::make('cancel')
            ->label('Anular aviso')
            ->icon(Heroicon::OutlinedNoSymbol)
            ->color('danger')
            ->authorize('cancel')
            ->modalHeading('Anular aviso')
            ->modalDescription('Usalo para avisos duplicados o cargados por error. Queda registrado en el historial.')
            ->schema([
                Textarea::make('reason')->label('Motivo')->required()->maxLength(2000),
            ])
            ->action(fn (array $data) => $this->runWorkflow(
                fn (RepairWorkflow $workflow, User $user) => $workflow->cancel($user, $this->repairRequest(), $data['reason']),
                'Aviso anulado',
            ));
    }

    private function uploadSignedFormAction(): Action
    {
        return Action::make('uploadSignedForm')
            ->label('Subir aviso firmado')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->authorize('attachSignedForm')
            ->modalHeading('Copia firmada por el conductor')
            ->schema([
                FileUpload::make('signed_form')
                    ->label('Archivo escaneado (PDF o imagen, máx. 10 MB)')
                    ->disk('local')
                    ->directory(RepairWorkflow::SIGNED_FORMS_DIRECTORY)
                    ->visibility('private')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(10240)
                    ->required(),
            ])
            ->action(function (array $data) {
                $previous = $this->repairRequest()->signed_form_path;

                try {
                    $this->runWorkflow(
                        fn (RepairWorkflow $workflow, User $user) => $workflow->attachSignedForm($user, $this->repairRequest(), $data['signed_form']),
                        'Copia firmada guardada',
                    );
                } catch (Halt $e) {
                    // Do not leave orphan files when the step is refused.
                    Storage::disk('local')->delete($data['signed_form']);

                    throw $e;
                }

                if ($previous !== null && $previous !== $data['signed_form']) {
                    Storage::disk('local')->delete($previous);
                }
            });
    }

    /**
     * Runs a workflow step and turns domain errors into user-facing notifications.
     *
     * @param  Closure(RepairWorkflow, User): RepairRequest  $step
     */
    private function runWorkflow(Closure $step, string $successTitle): void
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            $this->record = $step(app(RepairWorkflow::class), $user);
        } catch (ValidationException $e) {
            $this->failWith(collect($e->errors())->flatten()->first() ?? 'Datos inválidos.');
        } catch (InvalidRepairTransition|AuthorizationException $e) {
            $this->failWith($e->getMessage());
        }

        Notification::make()->title($successTitle)->success()->send();
    }

    private function failWith(string $message): never
    {
        Notification::make()->title('No se pudo completar la acción')->body($message)->danger()->send();

        throw new Halt;
    }

    private function repairRequest(): RepairRequest
    {
        /** @var RepairRequest $record */
        $record = $this->getRecord();

        return $record;
    }
}
