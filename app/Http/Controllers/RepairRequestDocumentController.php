<?php

namespace App\Http\Controllers;

use App\Models\RepairRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RepairRequestDocumentController extends Controller
{
    /**
     * Printable notice to be signed by the driver.
     */
    public function print(RepairRequest $repairRequest): View
    {
        Gate::authorize('view', $repairRequest);

        $repairRequest->load(['driver', 'reporter', 'assessor', 'decider']);

        return view('repair-requests.print', ['request' => $repairRequest]);
    }

    public function signedForm(RepairRequest $repairRequest): StreamedResponse
    {
        Gate::authorize('downloadSignedForm', $repairRequest);

        $path = (string) $repairRequest->signed_form_path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return Storage::disk('local')->download($path, "aviso-{$repairRequest->formattedNumber()}-firmado.{$extension}");
    }
}
