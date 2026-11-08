<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum RepairStatus: string implements HasColor, HasLabel
{
    /** Driver notice received, waiting for the technical report. */
    case Reported = 'reportado';

    /** Technical report submitted to superiors, waiting for a decision. */
    case PendingApproval = 'pendiente_aprobacion';

    case Approved = 'autorizado';
    case Rejected = 'rechazado';
    case InRepair = 'en_reparacion';
    case Completed = 'finalizado';
    case Cancelled = 'anulado';

    public function getLabel(): string
    {
        return match ($this) {
            self::Reported => 'Reportado',
            self::PendingApproval => 'Pendiente de aprobación',
            self::Approved => 'Autorizado',
            self::Rejected => 'Rechazado',
            self::InRepair => 'En reparación',
            self::Completed => 'Finalizado',
            self::Cancelled => 'Anulado',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Reported => 'info',
            self::PendingApproval => 'warning',
            self::Approved => 'primary',
            self::Rejected => 'danger',
            self::InRepair => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'gray',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Rejected, self::Completed, self::Cancelled], true);
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Reported => [self::PendingApproval, self::Cancelled],
            self::PendingApproval => [self::Approved, self::Rejected, self::Reported],
            self::Approved => [self::InRepair, self::Completed],
            self::InRepair => [self::Completed],
            self::Rejected, self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }
}
