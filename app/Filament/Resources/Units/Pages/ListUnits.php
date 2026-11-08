<?php

namespace App\Filament\Resources\Units\Pages;

use App\Filament\Resources\Units\UnitResource;
use App\Models\Unit;
use App\Services\FleetSpreadsheetImporter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class ListUnits extends ListRecords
{
    protected static string $resource = UnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importAction(),
            CreateAction::make(),
        ];
    }

    private function importAction(): Action
    {
        return Action::make('importSpreadsheet')
            ->label('Importar planilla')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('gray')
            ->authorize('create', Unit::class)
            ->modalHeading('Importar planilla de flota')
            ->modalDescription('CSV con las columnas NRO, TIPO, VEHICULO, NRO2, VEHICULO2 y CONDUCTOR (en Excel: Guardar como → CSV). Se puede volver a importar: actualiza sin duplicar.')
            ->modalSubmitActionLabel('Importar')
            ->schema([
                FileUpload::make('file')
                    ->label('Archivo CSV')
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                    ->maxSize(1024)
                    ->storeFiles(false)
                    ->required(),
            ])
            ->action(function (array $data) {
                /** @var TemporaryUploadedFile $file */
                $file = $data['file'];

                try {
                    $result = app(FleetSpreadsheetImporter::class)->import($file->get());
                } catch (ValidationException $e) {
                    Notification::make()
                        ->title('No se pudo importar')
                        ->body(collect($e->errors())->flatten()->first())
                        ->danger()
                        ->persistent()
                        ->send();

                    throw new Halt;
                }

                $body = "Unidades nuevas: {$result['units_created']} · actualizadas: {$result['units_updated']} · "
                    ."conductores nuevos: {$result['drivers_created']} · asignaciones: {$result['assignments']}";

                if ($result['warnings'] !== []) {
                    $body .= "\n\nRevisar:\n• ".implode("\n• ", $result['warnings']);
                }

                Notification::make()
                    ->title('Planilla importada')
                    ->body($body)
                    ->color($result['warnings'] === [] ? 'success' : 'warning')
                    ->icon($result['warnings'] === [] ? Heroicon::OutlinedCheckCircle : Heroicon::OutlinedExclamationTriangle)
                    ->persistent()
                    ->send();
            });
    }
}
