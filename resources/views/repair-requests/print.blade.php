@php
    /** @var \App\Models\RepairRequest $request */
    $canSeeCosts = auth()->user()?->can(\App\Support\Permissions::REPAIRS_VIEW_ALL);
    $money = fn ($value) => $value === null ? '—' : '$ '.number_format((float) $value, 2, ',', '.');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Aviso de reparación N° {{ $request->formattedNumber() }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12pt; color: #111; margin: 0; padding: 24px; background: #fff; }
        .sheet { max-width: 800px; margin: 0 auto; }
        header { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 16px; }
        h1 { font-size: 16pt; margin: 0 0 4px; }
        h2 { font-size: 12pt; margin: 20px 0 8px; padding: 4px 8px; background: #eee; border-left: 4px solid #111; }
        .number { font-size: 14pt; font-weight: bold; text-align: right; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 6px 8px; text-align: left; vertical-align: top; }
        th { width: 32%; background: #f6f6f6; font-weight: normal; }
        .text { white-space: pre-line; }
        .signatures { display: flex; gap: 48px; margin-top: 64px; }
        .signature { flex: 1; text-align: center; border-top: 1px solid #111; padding-top: 6px; font-size: 10pt; }
        .toolbar { text-align: right; margin-bottom: 16px; }
        .toolbar button { font-size: 11pt; padding: 8px 16px; cursor: pointer; }
        footer { margin-top: 32px; font-size: 9pt; color: #555; }
        @media print {
            body { padding: 0; }
            .toolbar { display: none; }
            h2 { break-after: avoid; }
            table, .signatures { break-inside: avoid; }
        }
    </style>
</head>
<body>
<div class="sheet">
    <div class="toolbar"><button type="button" onclick="window.print()">Imprimir</button></div>

    <header>
        <div>
            <h1>Aviso de rotura / falla de unidad</h1>
            <div>{{ config('app.name') }} — Procedimiento de comunicación y autorización de reparaciones</div>
        </div>
        <div class="number">
            Formulario N° {{ $request->formattedNumber() }}<br>
            <small>Estado: {{ $request->status->getLabel() }}</small>
        </div>
    </header>

    <h2>1. Aviso del conductor o responsable de la unidad</h2>
    <table>
        <tr><th>Conductor / responsable</th><td>{{ $request->driver->name }}</td></tr>
        <tr><th>Unidad</th><td>{{ $request->unit_label }}</td></tr>
        <tr><th>Tipo de aviso</th><td>{{ $request->problem_kind->getLabel() }}</td></tr>
        <tr><th>Fecha de detección</th><td>{{ $request->detected_on->format('d/m/Y') }}</td></tr>
        <tr><th>Descripción del problema</th><td class="text">{{ $request->problem_description }}</td></tr>
        <tr><th>Cargado por</th><td>{{ $request->reporter->name }} — {{ $request->created_at->format('d/m/Y H:i') }}</td></tr>
    </table>

    @if ($request->assessed_at)
        <h2>2. Informe técnico administrativo</h2>
        <table>
            <tr><th>Urgencia estimada</th><td>{{ $request->urgency?->getLabel() }}</td></tr>
            <tr><th>Observaciones de la inspección</th><td class="text">{{ $request->inspection_notes ?? '—' }}</td></tr>
            <tr><th>Repuestos o servicio estimado</th><td class="text">{{ $request->estimated_parts_or_service ?? '—' }}</td></tr>
            @if ($canSeeCosts)
                <tr><th>Costo estimado</th><td>{{ $money($request->estimated_cost) }}</td></tr>
            @endif
            <tr><th>Elaborado por</th><td>{{ $request->assessor?->name }} — {{ $request->assessed_at->format('d/m/Y H:i') }}</td></tr>
        </table>
    @endif

    @if ($request->decided_at)
        <h2>3. Decisión de superiores</h2>
        <table>
            <tr><th>Resultado</th><td>{{ $request->status->getLabel() }}</td></tr>
            <tr><th>Decidido por</th><td>{{ $request->decider?->name }} — {{ $request->decided_at->format('d/m/Y H:i') }}</td></tr>
            <tr><th>Observaciones</th><td class="text">{{ $request->decision_notes ?? '—' }}</td></tr>
        </table>
    @endif

    @if ($request->completed_on)
        <h2>4. Seguimiento</h2>
        <table>
            <tr><th>Reparación realizada</th><td class="text">{{ $request->work_performed }}</td></tr>
            <tr><th>Proveedor o mecánico</th><td>{{ $request->provider }}</td></tr>
            @if ($canSeeCosts)
                <tr><th>Costo final</th><td>{{ $money($request->final_cost) }}</td></tr>
            @endif
            <tr><th>Fecha de finalización</th><td>{{ $request->completed_on->format('d/m/Y') }}</td></tr>
        </table>
    @endif

    <div class="signatures">
        <div class="signature">Firma y aclaración<br>Conductor / responsable de la unidad</div>
        <div class="signature">Firma y aclaración<br>Encargado</div>
    </div>

    <footer>Impreso el {{ now()->format('d/m/Y H:i') }}. Archivar la copia firmada.</footer>
</div>
</body>
</html>
