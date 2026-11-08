<?php

namespace App\Support;

use App\Filament\Resources\RepairRequests\RepairRequestResource;
use App\Models\RepairRequest;

/**
 * Builds a wa.me "click to chat" link: WhatsApp opens with the text ready and the user
 * picks the contact or group. No API, cost or Meta verification involved.
 *
 * The message intentionally carries only a summary and the link: details stay behind login.
 */
final class WhatsAppLink
{
    public static function forRepairRequest(RepairRequest $request): string
    {
        $lines = [
            "Solicitud de reparación N° {$request->formattedNumber()}",
            "Estado: {$request->status->getLabel()}",
            "Unidad: {$request->unit_label}",
        ];

        if ($request->urgency !== null) {
            $lines[] = "Urgencia: {$request->urgency->getLabel()}";
        }

        $lines[] = 'Ver detalle: '.RepairRequestResource::getUrl('view', ['record' => $request]);

        return 'https://wa.me/?text='.rawurlencode(implode("\n", $lines));
    }
}
