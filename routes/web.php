<?php

use App\Http\Controllers\RepairRequestDocumentController;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Same check as the panel (default panel): inactive users or users without a role get 403.
Route::middleware(Authenticate::class)
    ->prefix('reparaciones/{repairRequest}')
    ->name('repair-requests.')
    ->group(function () {
        Route::get('imprimir', [RepairRequestDocumentController::class, 'print'])->name('print');
        Route::get('firmado', [RepairRequestDocumentController::class, 'signedForm'])->name('signed-form');
    });
