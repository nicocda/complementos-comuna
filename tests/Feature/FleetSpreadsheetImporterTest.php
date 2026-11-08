<?php

namespace Tests\Feature;

use App\Enums\UnitStatus;
use App\Enums\UnitType;
use App\Filament\Resources\Units\Pages\ListUnits;
use App\Models\Driver;
use App\Models\Unit;
use App\Services\FleetSpreadsheetImporter;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fixtures reproduce the real spreadsheet layout with fictitious people.
 */
class FleetSpreadsheetImporterTest extends TestCase
{
    use RefreshDatabase;

    private const CSV = <<<'CSV'
        NRO;TIPO;VEHICULO;NRO2;VEHICULO2;CONDUCTOR
        1;TRACTOR;MASSEY FERGUSON 1175;44;CARRO PLAYO VOLCADOR;JUAN PEREZ
        5;TRACTOR;FIAT 800;;;EN MAESTRANZA
        12;TRACTOR;AGRALE 575.4;;;ANA GOMEZ
        ;;;32;ROLO TRITURADOR;PEDRO SOSA
        26;PICK-UP;FORD F100 (AKX575) AZUL;25;HIDROELEVADOR;LUIS DIAZ
        30;PICK-UP;FORD F100 GRIS;25;HIDROELEVADOR;MARIA RUIZ
        35;MOTONIV;ZANELLO;;;JOSE LOPEZ-CARLOS VEGA
        40;TRACTOR;MASSEY FERGUSON 1185;S/N;DISCO ARADO ROJO;- LAURA PAZ
        ;;;;PATA CABRA;LAURA PAZ
        9;CAMION;DODGE DP500;;;JUAN PEREZ
        CSV;

    private FleetSpreadsheetImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->importer = app(FleetSpreadsheetImporter::class);
    }

    public function test_imports_units_implements_and_drivers(): void
    {
        $result = $this->importer->import(self::CSV);

        $tractor = Unit::query()->where('internal_number', 1)->sole();
        $this->assertSame(UnitType::Tractor, $tractor->type);
        $this->assertSame('MASSEY FERGUSON 1175', $tractor->name);

        $trailer = Unit::query()->where('internal_number', 44)->sole();
        $this->assertSame(UnitType::Trailer, $trailer->type);
        $this->assertTrue($trailer->attachedTo->is($tractor));

        // "EN MAESTRANZA" is a status, not a driver.
        $this->assertSame(UnitStatus::InWorkshop, Unit::query()->where('internal_number', 5)->sole()->status);
        $this->assertFalse(Driver::query()->where('name', 'like', '%Maestranza%')->exists());

        // Continuation row: implement attached to the previous unit; driver assigned to the implement.
        $roller = Unit::query()->where('internal_number', 32)->sole();
        $this->assertSame(UnitType::Implement, $roller->type);
        $this->assertSame(12, $roller->attachedTo->internal_number);
        $this->assertTrue($roller->drivers()->where('name', 'Pedro Sosa')->exists());

        // Plate between parentheses goes to its own field.
        $pickup = Unit::query()->where('internal_number', 26)->sole();
        $this->assertSame('FORD F100 AZUL', $pickup->name);
        $this->assertSame('AKX575', $pickup->license_plate);

        // Two drivers in one cell.
        $grader = Unit::query()->where('internal_number', 35)->sole();
        $this->assertEqualsCanonicalizing(['Jose Lopez', 'Carlos Vega'], $grader->drivers->pluck('name')->all());

        // S/N implements, leading "- " noise and a second S/N implement on a continuation row.
        $tractor40 = Unit::query()->where('internal_number', 40)->sole();
        $this->assertEqualsCanonicalizing(['DISCO ARADO ROJO', 'PATA CABRA'], $tractor40->implements->pluck('name')->all());
        $this->assertSame(['Laura Paz'], $tractor40->drivers->pluck('name')->all());

        // Same person on two units is one driver.
        $this->assertSame(1, Driver::query()->where('name', 'Juan Perez')->count());
        $this->assertSame(2, Driver::query()->where('name', 'Juan Perez')->sole()->units()->count());

        // Implement 25 listed under two pick-ups: kept on the last one, with a warning.
        $this->assertSame(30, Unit::query()->where('internal_number', 25)->sole()->attachedTo->internal_number);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('más de una unidad', $result['warnings'][0]);

        // 1, 44, 5, 12, 32, 26, 25, 30, 35, 40, disco S/N, pata cabra S/N, 9.
        $this->assertSame(13, $result['units_created']);
        // Juan Perez (x2 units), Ana Gomez, Pedro Sosa, Luis Diaz, Maria Ruiz, Jose Lopez, Carlos Vega, Laura Paz (x2 rows).
        $this->assertSame(8, $result['drivers_created']);
    }

    public function test_reimport_is_idempotent(): void
    {
        $this->importer->import(self::CSV);
        $units = Unit::query()->count();
        $drivers = Driver::query()->count();

        $result = $this->importer->import(self::CSV);

        $this->assertSame($units, Unit::query()->count());
        $this->assertSame($drivers, Driver::query()->count());
        $this->assertSame(0, $result['units_created']);
        $this->assertSame(0, $result['drivers_created']);
        $this->assertSame(0, $result['assignments']);
    }

    public function test_accepts_comma_delimiter_bom_and_windows_1252(): void
    {
        $csv = "\xEF\xBB\xBFNRO,TIPO,VEHICULO,NRO2,VEHICULO2,CONDUCTOR\n7,CAMION,IVECO 170E22,,,ADRIAN NUÑEZ\n";
        $this->importer->import($csv);
        $this->assertTrue(Driver::query()->where('name', 'Adrian Nuñez')->exists());

        $ansi = mb_convert_encoding("NRO;TIPO;VEHICULO;NRO2;VEHICULO2;CONDUCTOR\n8;CAMION;CHEVROLET;;;RUBÉN ÁLVAREZ\n", 'Windows-1252', 'UTF-8');
        $this->importer->import($ansi);
        $this->assertTrue(Driver::query()->where('name', 'Rubén Álvarez')->exists());
    }

    public function test_rejects_missing_columns(): void
    {
        $this->expectException(ValidationException::class);

        $this->importer->import("NRO;TIPO;VEHICULO\n1;TRACTOR;FIAT\n");
    }

    public function test_invalid_number_rolls_back_everything(): void
    {
        try {
            $this->importer->import("NRO;TIPO;VEHICULO;NRO2;VEHICULO2;CONDUCTOR\n1;TRACTOR;FIAT;;;ANA\nX1;TRACTOR;FIAT;;;ANA\n");
            $this->fail('Expected validation error.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Fila 3', $e->errors()['file'][0]);
        }

        $this->assertSame(0, Unit::query()->count());
        $this->assertSame(0, Driver::query()->count());
    }

    public function test_import_action_requires_fleet_permission(): void
    {
        $this->seedRoles();

        $this->actingAs($this->userWithRole(Permissions::ROLE_SUPERIOR));
        Livewire::test(ListUnits::class)->assertActionHidden('importSpreadsheet');

        $this->actingAs($this->userWithRole(Permissions::ROLE_SUPERVISOR));
        Livewire::test(ListUnits::class)
            ->callAction('importSpreadsheet', data: [
                'file' => UploadedFile::fake()->createWithContent('flota.csv', self::CSV),
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Planilla importada');

        $this->assertSame(13, Unit::query()->count());
    }
}
