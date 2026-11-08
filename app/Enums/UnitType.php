<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UnitType: string implements HasLabel
{
    case Tractor = 'tractor';
    case LoaderTractor = 'tractor_pala';
    case Backhoe = 'retro';
    case Truck = 'camion';
    case MotorGrader = 'motoniveladora';
    case Pickup = 'pickup';
    case Van = 'utilitario';
    case Tank = 'tanque';
    case Trailer = 'carro';
    case Implement = 'implemento';
    case Compactor = 'compactador';
    case Chipper = 'chipeadora';
    case Mower = 'helice';
    case LeafVacuum = 'chupa_hojas';
    case Other = 'otro';

    public function getLabel(): string
    {
        return match ($this) {
            self::Tractor => 'Tractor',
            self::LoaderTractor => 'Tractor pala',
            self::Backhoe => 'Retroexcavadora',
            self::Truck => 'Camión',
            self::MotorGrader => 'Motoniveladora',
            self::Pickup => 'Pick-up',
            self::Van => 'Utilitario',
            self::Tank => 'Tanque',
            self::Trailer => 'Carro / acoplado',
            self::Implement => 'Implemento',
            self::Compactor => 'Compactador',
            self::Chipper => 'Chipeadora',
            self::Mower => 'Hélice / desmalezadora',
            self::LeafVacuum => 'Chupa hojas',
            self::Other => 'Otro',
        };
    }

    /**
     * Maps the free-text "TIPO" column of the fleet spreadsheet to a type.
     */
    public static function fromSpreadsheet(string $value): self
    {
        $normalized = mb_strtoupper(trim($value));

        return match (true) {
            $normalized === 'TRACTOR PALA' => self::LoaderTractor,
            str_starts_with($normalized, 'TRACTOR') => self::Tractor,
            str_starts_with($normalized, 'RETRO') => self::Backhoe,
            str_starts_with($normalized, 'CAMION'), str_starts_with($normalized, 'CAMIÓN') => self::Truck,
            str_starts_with($normalized, 'MOTONIV') => self::MotorGrader,
            str_starts_with($normalized, 'PICK') => self::Pickup,
            str_starts_with($normalized, 'UTILITARIO') => self::Van,
            str_starts_with($normalized, 'TANQUE') => self::Tank,
            str_starts_with($normalized, 'CARRO'), str_starts_with($normalized, 'ACOPLADO') => self::Trailer,
            str_starts_with($normalized, 'COMPACTADOR') => self::Compactor,
            str_starts_with($normalized, 'CHIPEADORA') => self::Chipper,
            str_starts_with($normalized, 'HELICE'), str_starts_with($normalized, 'HÉLICE') => self::Mower,
            str_starts_with($normalized, 'HOJAS') => self::LeafVacuum,
            default => self::Other,
        };
    }
}
