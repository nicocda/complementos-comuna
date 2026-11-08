<?php

namespace App\Filament\Resources\Drivers\Schemas;

use App\Models\Driver;
use App\Models\Unit;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class DriverForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nombre y apellido')
                    ->required()
                    ->maxLength(150)
                    ->unique(ignoreRecord: true),
                TextInput::make('phone')
                    ->label('Teléfono')
                    ->tel()
                    ->maxLength(40),
                Select::make('user_id')
                    ->label('Usuario del sistema')
                    ->helperText('Opcional: vinculalo si el conductor carga sus propios avisos.')
                    ->relationship(
                        'user',
                        'name',
                        modifyQueryUsing: fn (Builder $query, ?Driver $record) => $query->where(
                            fn (Builder $query) => $query
                                ->whereDoesntHave('driver')
                                ->when($record?->user_id, fn (Builder $query, int $userId) => $query->orWhereKey($userId)),
                        ),
                    )
                    ->searchable()
                    ->preload()
                    ->unique(ignoreRecord: true),
                Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true),
                Select::make('units')
                    ->label('Unidades asignadas')
                    ->relationship('units', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Unit $unit) => $unit->label())
                    ->multiple()
                    ->searchable(['name', 'internal_number'])
                    ->preload()
                    ->columnSpanFull(),
            ]);
    }
}
