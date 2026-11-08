<?php

namespace App\Notifications;

use App\Enums\RepairStatus;
use App\Filament\Resources\RepairRequests\RepairRequestResource;
use App\Models\RepairRequest;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class RepairRequestStatusChanged extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly RepairStatus $status;

    public readonly bool $returnedForReview;

    public function __construct(
        public readonly RepairRequest $repairRequest,
        public readonly User $actor,
    ) {
        // Freeze what triggered the notification: the queued job re-fetches the model, which may have moved on.
        $this->status = $repairRequest->status;
        $this->returnedForReview = $repairRequest->status === RepairStatus::Reported && $repairRequest->assessed_at !== null;
        $this->afterCommit();
    }

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->title())
            ->greeting("Hola {$notifiable->name},")
            ->line($this->body())
            ->line("Unidad: {$this->repairRequest->unit_label}")
            ->action('Ver solicitud', $this->url())
            ->salutation('Sistema de gestión interna');
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        return FilamentNotification::make()
            ->title($this->title())
            ->body($this->body())
            ->icon('heroicon-o-wrench-screwdriver')
            ->color($this->status->getColor())
            ->actions([
                Action::make('ver')
                    ->label('Ver solicitud')
                    ->url($this->url())
                    ->markAsRead(),
            ])
            ->getDatabaseMessage();
    }

    public function title(): string
    {
        $number = $this->repairRequest->formattedNumber();

        return match ($this->status) {
            RepairStatus::Reported => $this->returnedForReview
                ? "Solicitud N° {$number} devuelta para revisión"
                : "Nuevo aviso de reparación N° {$number}",
            RepairStatus::PendingApproval => "Solicitud N° {$number} pendiente de aprobación",
            RepairStatus::Approved => "Solicitud N° {$number} autorizada",
            RepairStatus::Rejected => "Solicitud N° {$number} rechazada",
            RepairStatus::InRepair => "Solicitud N° {$number} en reparación",
            RepairStatus::Completed => "Reparación N° {$number} finalizada",
            RepairStatus::Cancelled => "Solicitud N° {$number} anulada",
        };
    }

    public function body(): string
    {
        return match ($this->status) {
            RepairStatus::Reported => $this->returnedForReview
                ? "{$this->actor->name} pidió más información antes de decidir. Revisá el informe técnico."
                : "{$this->actor->name} registró un aviso que requiere el informe técnico.",
            RepairStatus::PendingApproval => "{$this->actor->name} elevó el informe técnico. Requiere tu aprobación.",
            RepairStatus::Approved => "{$this->actor->name} autorizó la reparación.",
            RepairStatus::Rejected => "{$this->actor->name} rechazó la reparación.",
            RepairStatus::InRepair => "{$this->actor->name} inició la gestión de compra y reparación.",
            RepairStatus::Completed => "{$this->actor->name} registró la reparación como finalizada.",
            RepairStatus::Cancelled => "{$this->actor->name} anuló la solicitud.",
        };
    }

    private function url(): string
    {
        return RepairRequestResource::getUrl('view', ['record' => $this->repairRequest]);
    }
}
