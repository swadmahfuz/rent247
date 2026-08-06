<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        @page { margin: 12mm 10mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; margin: 0; }
        .bill { padding: 12px 24px; }
        .bill.break { page-break-after: always; }
        h1 { font-size: 14px; margin: 0 0 4px; text-align: center; }
        .addr { text-align: center; margin-bottom: 10px; font-size: 10px; }
        .meta div { margin: 2px 0; }
        .intro { margin-bottom: 4px; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 6px; table-layout: fixed; }
        table.lines th, table.lines td {
            border: 1px solid #333;
            padding: 4px 6px;
            vertical-align: middle;
        }
        table.lines th { background: #f0f0f0; text-align: left; font-size: 10px; }
        .col-sl { width: 18px; }
        .col-period { width: 42px; }
        .col-amount { width: 68px; }
        .num { text-align: right; white-space: nowrap; }
        .words { margin-top: 10px; font-size: 10px; }
        table.sign { width: 100%; border-collapse: collapse; margin-top: 26px; font-size: 10px; }
        table.sign td { vertical-align: bottom; padding: 0; }
        td.sign-date { white-space: nowrap; padding-bottom: 4px; }
        .sign-date .value { padding-left: 22px; }
        td.sign-owner { width: 46%; }
        table.sign-img-box { width: 100%; height: 56px; border-collapse: collapse; }
        table.sign-img-box td { text-align: center; vertical-align: bottom; padding: 0; }
        .sign-img { max-height: 56px; max-width: 170px; }
        .sign-line {
            border-top: 1px solid #333;
            padding-top: 3px;
            text-align: center;
        }
    </style>
</head>
<body>
@foreach ($sections as $section)
<div class="bill {{ $loop->last ? '' : 'break' }}">
    <h1>{{ $section['title'] }}</h1>
    <div class="addr">{{ $property->address }}</div>
    <div class="meta">
        <div><strong>Floor:</strong> {{ $lease->unit->label }}</div>
        <div><strong>Owner:</strong> {{ $property->owner_display_name }}</div>
        <div><strong>Tenant:</strong> {{ $lease->tenant->name }}</div>
    </div>
    <div class="intro">Description of bill as under (period shown against each)</div>
    <table class="lines">
        <thead>
            <tr>
                <th class="col-sl">Sl.</th>
                <th>Description of Bill</th>
                <th class="col-period">Period</th>
                <th class="col-amount">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($section['lines'] as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line->description }}</td>
                    <td>{{ $line->period_label }}</td>
                    <td class="num">{{ number_format((float)$line->amount, 2) }}</td>
                </tr>
            @endforeach
            <tr>
                <td>{{ $section['lines']->count() + 1 }}</td>
                <td><strong>Total Amount</strong></td>
                <td></td>
                <td class="num"><strong>{{ number_format((float)$section['total'], 2) }}</strong></td>
            </tr>
        </tbody>
    </table>
    <div class="words"><strong>Amount in Words:</strong> {{ $section['amount_in_words'] }}</div>
    <table class="sign">
        <tr>
            <td class="sign-date">Date:<span class="value">{{ optional($billDate)->format('d F Y') }}</span></td>
            <td class="sign-owner">
                <table class="sign-img-box">
                    <tr>
                        <td>
                            @if (!empty($signatureDataUri))
                                <img class="sign-img" src="{{ $signatureDataUri }}" alt="Owner signature">
                            @endif
                        </td>
                    </tr>
                </table>
                <div class="sign-line">Signature of Owner/ on behalf</div>
            </td>
        </tr>
    </table>
</div>
@endforeach
</body>
</html>
