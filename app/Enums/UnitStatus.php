<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UnitStatus: string implements HasColor, HasLabel
{
    case Active = 'activa';
    case InWorkshop = 'en_maestranza';
    case OutOfService = 'baja';

    public function getLabel(): string
    {
        return match ($this) {
            self::Active => 'Activa',
            self::InWorkshop => 'En maestranza',
            self::OutOfService => 'De baja',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::InWorkshop => 'warning',
            self::OutOfService => 'gray',
        };
    }
}
