<?php

namespace App\Services;

use App\Enums\UnitStatus;
use App\Enums\UnitType;
use App\Models\Driver;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Imports the fleet spreadsheet (NRO, TIPO, VEHICULO, NRO2, VEHICULO2, CONDUCTOR) exported as CSV.
 *
 * - Rows without NRO/VEHICULO continue the previous unit (e.g. several implements of one tractor).
 * - "EN MAESTRANZA" in CONDUCTOR is a unit status, not a person.
 * - "NAME A-NAME B" in CONDUCTOR means two drivers.
 * - A plate between parentheses in VEHICULO, e.g. "FORD F100 (AKX575) AZUL", goes to license_plate.
 * - Idempotent: units are matched by internal number (or type + name when S/N), drivers by name.
 */
class FleetSpreadsheetImporter
{
    private const REQUIRED_COLUMNS = ['NRO', 'TIPO', 'VEHICULO', 'NRO2', 'VEHICULO2', 'CONDUCTOR'];

    private const MAX_ROWS = 2000;

    /** @var array<string, int> */
    private array $stats = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var array<int, string> implement id => main unit label it was attached to during this import */
    private array $implementOwners = [];

    /**
     * @return array{units_created: int, units_updated: int, drivers_created: int, assignments: int, warnings: list<string>}
     */
    public function import(string $contents): array
    {
        $this->stats = ['units_created' => 0, 'units_updated' => 0, 'drivers_created' => 0, 'assignments' => 0];
        $this->warnings = [];
        $this->implementOwners = [];

        $rows = $this->parse($contents);

        DB::transaction(function () use ($rows) {
            $current = null;

            foreach ($rows as $line => $row) {
                $current = $this->importRow($line, $row, $current);
            }
        });

        return [...$this->stats, 'warnings' => $this->warnings];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importRow(int $line, array $row, ?Unit $current): ?Unit
    {
        $main = null;

        if ($row['NRO'] !== '' || $row['VEHICULO'] !== '') {
            $main = $this->upsertUnit(
                number: $this->parseNumber($row['NRO'], $line, 'NRO'),
                type: UnitType::fromSpreadsheet($row['TIPO']),
                description: $row['VEHICULO'] !== '' ? $row['VEHICULO'] : $row['TIPO'],
            );
            $current = $main;
        }

        $implement = null;

        if ($row['VEHICULO2'] !== '') {
            if ($current === null) {
                $this->warnings[] = "Fila {$line}: el implemento \"{$row['VEHICULO2']}\" no tiene unidad principal; se cargó sin acoplar.";
            }

            $implement = $this->upsertUnit(
                number: $this->parseNumber($row['NRO2'], $line, 'NRO2'),
                type: $this->implementType($row['VEHICULO2']),
                description: $row['VEHICULO2'],
            );

            if ($current !== null) {
                $this->attach($implement, $current, $line);
            }
        }

        $this->assignDrivers($row['CONDUCTOR'], $main ?? $implement, $line);

        return $current;
    }

    private function upsertUnit(?int $number, UnitType $type, string $description): Unit
    {
        [$name, $plate] = $this->splitPlate($description);

        $unit = $number !== null
            ? Unit::query()->firstOrNew(['internal_number' => $number])
            : Unit::query()->firstOrNew(['internal_number' => null, 'type' => $type, 'name' => $name]);

        $unit->fill([
            'type' => $type,
            'name' => $name,
            'license_plate' => $plate ?? $unit->license_plate,
        ]);

        if (! $unit->exists) {
            $unit->status = UnitStatus::Active;
        }

        $created = ! $unit->exists;

        if ($created || $unit->isDirty()) {
            $unit->save();
            $this->stats[$created ? 'units_created' : 'units_updated']++;
        }

        return $unit;
    }

    private function attach(Unit $implement, Unit $owner, int $line): void
    {
        if (isset($this->implementOwners[$implement->getKey()]) && $implement->attached_to_unit_id !== $owner->getKey()) {
            $this->warnings[] = "Fila {$line}: \"{$implement->label()}\" figura en más de una unidad; quedó acoplado a {$owner->label()} (antes: {$this->implementOwners[$implement->getKey()]}).";
        }

        $implement->attached_to_unit_id = $owner->getKey();
        $implement->save();
        $this->implementOwners[$implement->getKey()] = $owner->label();
    }

    private function assignDrivers(string $value, ?Unit $unit, int $line): void
    {
        $value = trim($value, " \t-–");

        if ($value === '') {
            return;
        }

        if ($unit === null) {
            $this->warnings[] = "Fila {$line}: conductor \"{$value}\" sin unidad; se ignoró.";

            return;
        }

        if (mb_strtoupper($value) === 'EN MAESTRANZA') {
            $unit->update(['status' => UnitStatus::InWorkshop]);

            return;
        }

        foreach (preg_split('/\s*[-–\/]\s*/u', $value) ?: [] as $name) {
            $name = $this->normalizeName($name);

            if ($name === '') {
                continue;
            }

            $driver = Driver::query()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

            if ($driver === null) {
                $driver = Driver::query()->create(['name' => $name, 'is_active' => true]);
                $this->stats['drivers_created']++;
            }

            $changes = $driver->units()->syncWithoutDetaching([$unit->getKey()]);
            $this->stats['assignments'] += count($changes['attached']);
        }
    }

    private function implementType(string $description): UnitType
    {
        $type = UnitType::fromSpreadsheet($description);

        return in_array($type, [UnitType::Trailer, UnitType::Tank], true) ? $type : UnitType::Implement;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitPlate(string $description): array
    {
        // Argentine plates: ABC123 (old) or AB123CD (Mercosur).
        if (preg_match('/\(\s*([A-Z]{3}\d{3}|[A-Z]{2}\d{3}[A-Z]{2})\s*\)/iu', $description, $match)) {
            $name = trim(preg_replace('/\s+/u', ' ', str_replace($match[0], ' ', $description)) ?? $description);

            return [$name, mb_strtoupper($match[1])];
        }

        return [$description, null];
    }

    private function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');

        return mb_convert_case(mb_strtolower($name), MB_CASE_TITLE);
    }

    private function parseNumber(string $value, int $line, string $column): ?int
    {
        $value = trim($value);

        if ($value === '' || in_array(mb_strtoupper($value), ['S/N', 'SN', 'S/Nº', '-'], true)) {
            return null;
        }

        if (! ctype_digit($value) || (int) $value < 1 || (int) $value > 99999) {
            throw ValidationException::withMessages([
                'file' => "Fila {$line}: el valor \"{$value}\" de la columna {$column} no es un número de interno válido.",
            ]);
        }

        return (int) $value;
    }

    /**
     * @return array<int, array<string, string>> keyed by spreadsheet line number
     */
    private function parse(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        // Excel in Spanish saves CSV as Windows-1252 unless told otherwise.
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($contents)) ?: [];

        if ($lines === [] || $lines[0] === '') {
            throw ValidationException::withMessages(['file' => 'El archivo está vacío.']);
        }

        if (count($lines) - 1 > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'El archivo supera las '.self::MAX_ROWS.' filas.']);
        }

        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $header = array_map(fn ($cell) => mb_strtoupper(trim((string) $cell)), str_getcsv($lines[0], $delimiter, '"', ''));

        $missing = array_diff(self::REQUIRED_COLUMNS, $header);

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'file' => 'Faltan columnas: '.implode(', ', $missing).'. Se esperan: '.implode(', ', self::REQUIRED_COLUMNS).'.',
            ]);
        }

        $rows = [];

        foreach (array_slice($lines, 1, preserve_keys: true) as $index => $line) {
            $cells = str_getcsv($line, $delimiter, '"', '');

            if (count(array_filter($cells, fn ($cell) => trim((string) $cell) !== '')) === 0) {
                continue;
            }

            $row = [];

            foreach (self::REQUIRED_COLUMNS as $column) {
                $position = array_search($column, $header, true);
                $row[$column] = trim((string) ($cells[$position] ?? ''));
            }

            $rows[$index + 1] = $row;
        }

        return $rows;
    }
}
