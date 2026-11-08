<?php

namespace App\Filament\Resources\RepairRequests\Pages;

use App\Filament\Resources\RepairRequests\RepairRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRepairRequests extends ListRecords
{
    protected static string $resource = RepairRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo aviso'),
        ];
    }
}
