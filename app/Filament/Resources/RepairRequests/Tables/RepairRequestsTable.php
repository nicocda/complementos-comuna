<?php

namespace App\Filament\Resources\RepairRequests\Tables;

use App\Enums\RepairStatus;
use App\Enums\RepairUrgency;
use App\Models\RepairRequest;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class RepairRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('number', 'desc')
            ->columns([
                TextColumn::make('number')
                    ->label('N°')
                    ->formatStateUsing(fn (RepairRequest $record) => $record->formattedNumber())
                    ->sortable()
                    ->searchable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->sortable(),
                TextColumn::make('unit_label')
                    ->label('Unidad')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('driver.name')
                    ->label('Conductor')
                    ->searchable(),
                TextColumn::make('urgency')
                    ->label('Urgencia')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('detected_on')
                    ->label('Detectado')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('final_cost')
                    ->label('Costo final')
                    ->money('ARS', locale: 'es_AR')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Estado')->options(RepairStatus::class)->multiple(),
                SelectFilter::make('urgency')->label('Urgencia')->options(RepairUrgency::class),
                SelectFilter::make('unit')->label('Unidad')->relationship('unit', 'name')->searchable()->preload(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
