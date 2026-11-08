<?php

namespace App\Filament\Resources\RepairRequests\Pages;

use App\Filament\Resources\RepairRequests\RepairRequestResource;
use App\Models\RepairRequest;
use App\Models\User;
use App\Services\RepairWorkflow;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateRepairRequest extends CreateRecord
{
    protected static string $resource = RepairRequestResource::class;

    protected static bool $canCreateAnother = false;

    protected static ?string $title = 'Nuevo aviso de reparación';

    protected function handleRecordCreation(array $data): Model
    {
        /** @var User $user */
        $user = auth()->user();

        try {
            return app(RepairWorkflow::class)->report($user, $data);
        } catch (ValidationException $e) {
            // Map service errors onto the form fields (state path "data.*").
            throw ValidationException::withMessages(
                collect($e->errors())->mapWithKeys(fn (array $messages, string $key) => ["data.{$key}" => $messages])->all(),
            );
        }
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        /** @var RepairRequest $record */
        $record = $this->getRecord();

        return "Aviso N° {$record->formattedNumber()} registrado";
    }

    protected function getRedirectUrl(): string
    {
        return RepairRequestResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
