<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        h1 { font-size: 16px; margin-bottom: 4px; }
        h2 { font-size: 12px; margin: 16px 0 6px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        th, td { border: 1px solid #333; padding: 4px 6px; }
        th { background: #eee; text-align: left; }
        .num { text-align: right; }
        .muted { color: #555; margin-bottom: 12px; }
    </style>
</head>
<body>
    <h1>{{ $property->name }} — Summary of Rent for {{ $period->label }}</h1>
    <div class="muted">Date: {{ optional($period->bill_date)->format('d F Y') }}</div>

    <h2>Invoices</h2>
    <table>
        <thead>
            <tr>
                <th>Unit</th>
                <th>Tenant</th>
                <th>Status</th>
                <th>Total</th>
                <th>Paid</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($period->invoices as $invoice)
                <tr>
                    <td>{{ $invoice->lease->unit->label ?? '' }}</td>
                    <td>{{ $invoice->lease->tenant->name ?? '' }}</td>
                    <td>{{ $invoice->status }}</td>
                    <td class="num">{{ number_format((float)$invoice->total_amount, 2) }}</td>
                    <td class="num">{{ number_format((float)$invoice->paid_amount, 2) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3"><strong>Total</strong></td>
                <td class="num"><strong>{{ number_format((float)$period->invoices->sum('total_amount'), 2) }}</strong></td>
                <td class="num"><strong>{{ number_format((float)$period->invoices->sum('paid_amount'), 2) }}</strong></td>
            </tr>
        </tbody>
    </table>

    <h2>Meter Inputs</h2>
    <table>
        <thead>
            <tr><th>Meter</th><th>Kind</th><th>Amount</th></tr>
        </thead>
        <tbody>
            @foreach ($period->meterInputs as $input)
                <tr>
                    <td>{{ $input->meter->name ?? '' }}@if($input->meter?->code) · meter {{ $input->meter->code }}@endif</td>
                    <td>{{ $input->meter->kind ?? '' }}</td>
                    <td class="num">{{ number_format((float)$input->amount, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
</body>
</html>
