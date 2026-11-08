<?php

namespace App\Filament\Resources\RepairRequests\Schemas;

use App\Enums\ProblemKind;
use App\Enums\UnitStatus;
use App\Models\Driver;
use App\Models\Unit;
use App\Models\User;
use App\Support\Permissions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

/**
 * Step 1 form: the driver's notice. The rest of the lifecycle is handled by actions on the view page.
 */
class RepairRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('unit_id')
                    ->label('Unidad')
                    ->options(fn () => self::unitOptions())
                    ->searchable()
                    ->required()
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set) {
                        if (! self::canChooseDriver() || $state === null) {
                            return;
                        }

                        // Pre-select the driver when the unit has exactly one assigned.
                        $drivers = Unit::query()->find($state)?->drivers()->where('is_active', true)->pluck('drivers.id');

                        if ($drivers?->count() === 1) {
                            $set('driver_id', $drivers->first());
                        }
                    }),
                Select::make('driver_id')
                    ->label('Conductor / responsable')
                    ->options(fn () => Driver::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required(fn () => self::canChooseDriver())
                    ->visible(fn () => self::canChooseDriver()),
                Radio::make('problem_kind')
                    ->label('Tipo de aviso')
                    ->options(ProblemKind::class)
                    ->required(),
                DatePicker::make('detected_on')
                    ->label('Fecha de detección')
                    ->default(today())
                    ->maxDate(today())
                    ->required(),
                Textarea::make('problem_description')
                    ->label('Descripción del problema detectado')
                    ->required()
                    ->minLength(10)
                    ->maxLength(5000)
                    ->rows(5)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * @return array<int, string>
     */
    private static function unitOptions(): array
    {
        return Unit::query()
            ->where('status', '!=', UnitStatus::OutOfService)
            ->orderByRaw('internal_number is null')
            ->orderBy('internal_number')
            ->get()
            ->mapWithKeys(fn (Unit $unit) => [$unit->getKey() => $unit->label()])
            ->all();
    }

    private static function canChooseDriver(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return $user?->can(Permissions::REPAIRS_VIEW_ALL) ?? false;
    }
}
