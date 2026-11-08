<?php

namespace App\Filament\Resources\Units\Tables;

use App\Enums\UnitStatus;
use App\Enums\UnitType;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class UnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('internal_number')
            ->columns([
                TextColumn::make('internal_number')
                    ->label('Interno')
                    ->placeholder('S/N')
                    ->sortable()
                    ->searchable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Descripción')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('license_plate')
                    ->label('Patente')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('attachedTo.name')
                    ->label('Acoplado a')
                    ->toggleable(),
                TextColumn::make('drivers.name')
                    ->label('Conductores')
                    ->listWithLineBreaks()
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')->label('Tipo')->options(UnitType::class),
                SelectFilter::make('status')->label('Estado')->options(UnitStatus::class),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
