<?php

namespace App\Filament\Resources\RepairRequests\Schemas;

use App\Models\RepairRequest;
use App\Support\Permissions;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RepairRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('1. Aviso del conductor')
                    ->columns(3)
                    ->schema([
                        TextEntry::make('number')
                            ->label('N° de formulario')
                            ->formatStateUsing(fn (RepairRequest $record) => $record->formattedNumber()),
                        TextEntry::make('status')->label('Estado')->badge(),
                        TextEntry::make('detected_on')->label('Fecha de detección')->date('d/m/Y'),
                        TextEntry::make('unit_label')->label('Unidad')->columnSpan(2),
                        TextEntry::make('driver.name')->label('Conductor / responsable'),
                        TextEntry::make('problem_kind')->label('Tipo de aviso'),
                        TextEntry::make('reporter.name')->label('Cargado por'),
                        TextEntry::make('created_at')->label('Fecha de carga')->dateTime('d/m/Y H:i'),
                        TextEntry::make('problem_description')
                            ->label('Descripción del problema')
                            ->columnSpanFull(),
                        TextEntry::make('signed_form_path')
                            ->label('Formulario firmado')
                            ->formatStateUsing(fn () => 'Descargar copia firmada')
                            ->url(fn (RepairRequest $record) => auth()->user()?->can('downloadSignedForm', $record)
                                ? route('repair-requests.signed-form', $record)
                                : null)
                            ->placeholder('Pendiente de subir')
                            ->columnSpanFull(),
                    ]),
                Section::make('2. Informe técnico administrativo')
                    ->columns(3)
                    ->visible(fn (RepairRequest $record) => $record->assessed_at !== null)
                    ->schema([
                        TextEntry::make('urgency')->label('Urgencia estimada')->badge(),
                        TextEntry::make('estimated_cost')
                            ->label('Costo estimado')
                            ->money('ARS', locale: 'es_AR')
                            ->placeholder('Sin estimar')
                            ->visible(fn () => self::canSeeCosts()),
                        TextEntry::make('assessor.name')->label('Elaborado por'),
                        TextEntry::make('inspection_notes')
                            ->label('Observaciones de la inspección')
                            ->placeholder('Sin inspección')
                            ->columnSpanFull(),
                        TextEntry::make('estimated_parts_or_service')
                            ->label('Repuestos o servicio estimado')
                            ->placeholder('Sin estimar')
                            ->columnSpanFull(),
                    ]),
                Section::make('3. Decisión de superiores')
                    ->columns(3)
                    ->visible(fn (RepairRequest $record) => $record->decided_at !== null)
                    ->schema([
                        TextEntry::make('decider.name')->label('Decidido por'),
                        TextEntry::make('decided_at')->label('Fecha de autorización / rechazo')->dateTime('d/m/Y H:i'),
                        TextEntry::make('decision_notes')->label('Observaciones')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('4. Seguimiento')
                    ->columns(3)
                    ->visible(fn (RepairRequest $record) => $record->provider !== null || $record->completed_on !== null)
                    ->schema([
                        TextEntry::make('provider')->label('Proveedor o mecánico')->placeholder('—'),
                        TextEntry::make('final_cost')
                            ->label('Costo final')
                            ->money('ARS', locale: 'es_AR')
                            ->placeholder('—')
                            ->visible(fn () => self::canSeeCosts()),
                        TextEntry::make('completed_on')->label('Fecha de finalización')->date('d/m/Y')->placeholder('—'),
                        TextEntry::make('work_performed')->label('Reparación realizada')->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make('Historial')
                    ->collapsible()
                    ->schema([
                        RepeatableEntry::make('events')
                            ->hiddenLabel()
                            ->columns(4)
                            ->schema([
                                TextEntry::make('created_at')->label('Fecha')->dateTime('d/m/Y H:i'),
                                TextEntry::make('user.name')->label('Usuario')->placeholder('—'),
                                TextEntry::make('to_status')->label('Estado')->badge(),
                                TextEntry::make('comment')->label('Comentario')->placeholder('—'),
                            ]),
                    ]),
            ]);
    }

    /**
     * Drivers only follow their own notices; costs are for supervisors and superiors.
     */
    private static function canSeeCosts(): bool
    {
        return auth()->user()?->can(Permissions::REPAIRS_VIEW_ALL) ?? false;
    }
}
