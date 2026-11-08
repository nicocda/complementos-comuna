<?php

namespace App\Filament\Resources\Units\Schemas;

use App\Enums\UnitStatus;
use App\Enums\UnitType;
use App\Models\Unit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class UnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('internal_number')
                    ->label('N° de interno')
                    ->helperText('Dejar vacío si la unidad no tiene número (S/N).')
                    ->integer()
                    ->minValue(1)
                    ->maxValue(99999)
                    ->unique(ignoreRecord: true),
                Select::make('type')
                    ->label('Tipo')
                    ->options(UnitType::class)
                    ->required()
                    ->searchable(),
                TextInput::make('name')
                    ->label('Marca / modelo / descripción')
                    ->required()
                    ->maxLength(150),
                TextInput::make('license_plate')
                    ->label('Patente')
                    ->maxLength(20)
                    ->dehydrateStateUsing(fn (?string $state) => $state === null ? null : mb_strtoupper(trim($state))),
                Select::make('status')
                    ->label('Estado')
                    ->options(UnitStatus::class)
                    ->default(UnitStatus::Active)
                    ->required(),
                Select::make('attached_to_unit_id')
                    ->label('Acoplado a')
                    ->helperText('Para implementos y carros: unidad que lo arrastra.')
                    ->relationship(
                        'attachedTo',
                        'name',
                        modifyQueryUsing: fn (Builder $query, ?Unit $record) => $query
                            ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                            ->orderBy('internal_number'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (Unit $record) => $record->label())
                    ->searchable(['name', 'internal_number'])
                    ->preload(),
                Textarea::make('notes')
                    ->label('Observaciones')
                    ->maxLength(2000)
                    ->columnSpanFull(),
            ]);
    }
}
