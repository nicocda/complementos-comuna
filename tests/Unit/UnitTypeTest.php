<?php

namespace Tests\Unit;

use App\Enums\UnitType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UnitTypeTest extends TestCase
{
    /**
     * @return array<string, array{string, UnitType}>
     */
    public static function spreadsheetValues(): array
    {
        return [
            'tractor' => ['TRACTOR', UnitType::Tractor],
            'tractor pala' => ['TRACTOR PALA', UnitType::LoaderTractor],
            'retro' => ['RETRO', UnitType::Backhoe],
            'camion' => ['CAMION', UnitType::Truck],
            'motoniv abbreviated' => ['MOTONIV', UnitType::MotorGrader],
            'pick-up' => ['PICK-UP', UnitType::Pickup],
            'utilitario' => ['UTILITARIO', UnitType::Van],
            'tanque' => ['TANQUE', UnitType::Tank],
            'carro' => ['CARRO', UnitType::Trailer],
            'helice' => ['HELICE', UnitType::Mower],
            'compactador' => ['COMPACTADOR', UnitType::Compactor],
            'chipeadora' => ['CHIPEADORA', UnitType::Chipper],
            'hojas' => ['HOJAS', UnitType::LeafVacuum],
            'lowercase with spaces' => ['  tractor ', UnitType::Tractor],
            'unknown' => ['GRUA', UnitType::Other],
        ];
    }

    #[DataProvider('spreadsheetValues')]
    public function test_maps_spreadsheet_type(string $value, UnitType $expected): void
    {
        $this->assertSame($expected, UnitType::fromSpreadsheet($value));
    }
}
