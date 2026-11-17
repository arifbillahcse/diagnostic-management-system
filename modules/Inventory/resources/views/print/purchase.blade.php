@php($state = $purchase->state())
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Purchase {{ $purchase->purchase_no }}</title>
    <style>
        @page { size: A4; margin: 12mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 10pt; margin: 0; padding: 12px; }
        h1 { font-size: 14pt; margin: 0 0 2px; }
        .sub { color: #333; margin-bottom: 10px; }
        .row { display: flex; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
        th { background: #eee; }
        .num { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; }
        .void { border: 2px solid #b91c1c; color: #b91c1c; padding: 4px 8px; display: inline-block; font-weight: bold; margin-bottom: 8px; }
        .sign { display: flex; justify-content: space-between; margin-top: 40px; }
        .sign span { border-top: 1px solid #111; padding-top: 2px; min-width: 55mm; text-align: center; }
        .actions { text-align: center; margin-bottom: 12px; }
        button { font-size: 12pt; padding: 8px 20px; cursor: pointer; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Print</button></div>
    <h1>{{ $clinic['name'] }}</h1>
    <div class="sub">{{ $clinic['address'] }} @if ($clinic['phone'])· {{ $clinic['phone'] }}@endif</div>
    @if ($state === 'void')<div class="void">VOID: {{ $purchase->void_reason }}</div>@endif
    <div class="row">
        <div>
            <strong>Purchase {{ $purchase->purchase_no }}</strong><br>
            Date: {{ $purchase->purchase_date->format('d M Y') }}<br>
            Supplier: <strong>{{ $purchase->supplier?->name }}</strong>@if ($purchase->supplier_bill_no) · bill {{ $purchase->supplier_bill_no }}@endif
        </div>
        <div style="text-align:right">
            Status: {{ ['void' => 'Void', 'paid' => 'Paid', 'unpaid' => 'Unpaid'][$state] }}@if ($purchase->paid_at) ({{ $purchase->paid_at->timezone($tz)->format('d M Y') }})@endif<br>
            Entered by: {{ $purchase->creator?->name ?? '-' }}
        </div>
    </div>
    <table>
        <thead><tr><th>#</th><th>Item</th><th>Batch</th><th>Expires</th><th class="num">Quantity</th><th class="num">Unit cost</th><th class="num">Amount</th></tr></thead>
        <tbody>
            @foreach ($purchase->lines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $line->item?->code }} · {{ $line->item?->name }}</td>
                    <td>{{ $line->batch_no ?? '-' }}</td>
                    <td>{{ $line->expires_on?->format('d M Y') ?? '-' }}</td>
                    <td class="num">{{ $line->quantity->formatted() }} {{ $line->item?->unit }}</td>
                    <td class="num">{{ $line->unit_cost->formatted() }}</td>
                    <td class="num">{{ $line->line_total->formatted() }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot><tr><td colspan="6">Total</td><td class="num">{{ $purchase->total->formatted() }}</td></tr></tfoot>
    </table>
    @if ($purchase->note)<p>Note: {{ $purchase->note }}</p>@endif
    <div class="sign"><span>Received by</span><span>Checked by</span></div>
</body>
</html>
