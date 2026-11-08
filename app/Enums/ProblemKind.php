<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ProblemKind: string implements HasLabel
{
    case Breakdown = 'rotura';
    case PotentialFailure = 'falla';

    public function getLabel(): string
    {
        return match ($this) {
            self::Breakdown => 'Rotura ya producida',
            self::PotentialFailure => 'Falla que puede derivar en rotura',
        };
    }
}
