<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 16mm 14mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; margin: 0; }
        h1 { font-size: 16px; margin: 0 0 4px; text-align: center; }
        .addr { text-align: center; margin-bottom: 18px; font-size: 10px; color: #444; }
        .meta { margin-bottom: 16px; }
        .meta div { margin: 3px 0; }
        table.box { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.box th, table.box td {
            border: 1px solid #333;
            padding: 8px 10px;
            text-align: left;
        }
        table.box th { background: #f0f0f0; width: 35%; }
        .amount { font-size: 18px; font-weight: bold; }
        .words { margin-top: 14px; font-size: 10px; }
        .footer { margin-top: 28px; text-align: right; font-size: 10px; }
        .footer .line {
            border-top: 1px solid #333;
            display: inline-block;
            min-width: 160px;
            padding-top: 4px;
            text-align: center;
        }
    </style>
</head>
<body>
    <h1>Payment Receipt</h1>
    <div class="addr">{{ $property->name }}@if($property->address) · {{ $property->address }}@endif</div>

    <div class="meta">
        <div><strong>Receipt #:</strong> {{ $payment->id }}</div>
        <div><strong>Date paid:</strong> {{ optional($payment->paid_on)->format('d F Y') }}</div>
        <div><strong>Tenant:</strong> {{ $lease->tenant->name }}</div>
        <div><strong>Unit / Floor:</strong> {{ $lease->unit->label }}</div>
        <div><strong>Invoice:</strong> {{ $invoice->number ?: ('#'.$invoice->id) }} ({{ $invoice->billingPeriod?->label }})</div>
    </div>

    <table class="box">
        <tr>
            <th>Amount received</th>
            <td class="amount">{{ number_format((float) $payment->amount, 2) }} {{ $currency }}</td>
        </tr>
        <tr>
            <th>Method</th>
            <td>{{ $payment->method ?: '—' }}</td>
        </tr>
        <tr>
            <th>Note</th>
            <td>{{ $payment->note ?: '—' }}</td>
        </tr>
        <tr>
            <th>Invoice total</th>
            <td>{{ number_format((float) $invoice->total_amount, 2) }}</td>
        </tr>
        <tr>
            <th>Paid to date</th>
            <td>{{ number_format((float) $invoice->paid_amount, 2) }}</td>
        </tr>
        <tr>
            <th>Balance remaining</th>
            <td>{{ number_format((float) $invoice->balance, 2) }}</td>
        </tr>
    </table>

    <div class="words"><strong>Amount in Words:</strong> {{ $amountInWords }}</div>

    <div class="footer">
        <div class="line">Received by / on behalf of owner</div>
    </div>
</body>
</html>
