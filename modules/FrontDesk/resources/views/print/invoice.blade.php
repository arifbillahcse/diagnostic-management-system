@php
    use Modules\FrontDesk\Enums\RefundStatus;
    $tz = 'Asia/Dhaka';
    $money = fn ($amount) => $amount->formatted();
    $approved = $invoice->refunds->where('status', RefundStatus::Approved);
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->invoice_no }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 12pt; margin: 0; padding: 16px; }
        .sheet { max-width: 180mm; margin: 0 auto; }
        header { text-align: center; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 12px; }
        h1 { font-size: 18pt; margin: 0 0 4px; }
        .sub { font-size: 10pt; white-space: pre-line; }
        h2 { font-size: 13pt; margin: 0 0 8px; text-align: center; letter-spacing: .08em; }
        .meta { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; margin-bottom: 12px; font-size: 11pt; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 6px 4px; border-bottom: 1px solid #999; text-align: left; }
        th { border-bottom: 1.5px solid #111; font-size: 10.5pt; }
        .num { text-align: right; white-space: nowrap; }
        .totals { margin-left: auto; width: 60%; margin-top: 8px; }
        .totals td { border: 0; padding: 3px 4px; }
        .totals .strong td { font-weight: bold; border-top: 1.5px solid #111; }
        .void { text-align: center; font-weight: bold; border: 2px solid #111; padding: 6px; margin-bottom: 12px; }
        footer { margin-top: 28px; font-size: 9.5pt; display: flex; justify-content: space-between; }
        .actions { text-align: center; margin: 16px 0; }
        button { font-size: 12pt; padding: 8px 20px; cursor: pointer; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="actions"><button type="button" onclick="window.print()">Print</button></div>

    <header>
        <h1>{{ $clinic['name'] }}</h1>
        <div class="sub">{{ $clinic['address'] }}@if ($clinic['phone']) &middot; {{ $clinic['phone'] }}@endif</div>
        @if ($clinic['header'])<div class="sub">{{ $clinic['header'] }}</div>@endif
    </header>

    <h2>INVOICE</h2>

    @if ($invoice->isVoid())
        <div class="void">VOID: {{ $invoice->void_reason }}</div>
    @endif

    <div class="meta">
        <div><strong>Invoice:</strong> {{ $invoice->invoice_no }}</div>
        <div><strong>Date:</strong> {{ $invoice->issued_at->timezone($tz)->format('d M Y, h:i A') }}</div>
        <div><strong>Patient:</strong> {{ $invoice->patient->name }} ({{ $invoice->patient->patient_code }})</div>
        <div><strong>Age / sex:</strong> {{ $invoice->patient->ageLabel($invoice->issued_at) }} / {{ $invoice->patient->sex->label() }}</div>
        <div><strong>Phone:</strong> {{ $invoice->patient->phone ?? '-' }}</div>
        <div><strong>Referred by:</strong> {{ $invoice->order->referralDoctor?->name ?? '-' }}</div>
    </div>

    <table>
        <thead><tr><th style="width: 8%">#</th><th>Description</th><th class="num" style="width: 24%">Amount (Tk)</th></tr></thead>
        <tbody>
            @foreach ($invoice->lines as $i => $line)
                <tr><td>{{ $i + 1 }}</td><td>{{ $line->description }}</td><td class="num">{{ $money($line->amount) }}</td></tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Total</td><td class="num">{{ $money($invoice->total) }}</td></tr>
        <tr><td>Paid</td><td class="num">{{ $money($invoice->paid_total) }}</td></tr>
        @if ($approved->isNotEmpty())
            <tr><td>Refunded</td><td class="num">{{ $money($invoice->refunded_total) }}</td></tr>
        @endif
        <tr class="strong">
            @if ($invoice->credit()->isPositive())
                <td>To be refunded</td><td class="num">{{ $money($invoice->credit()) }}</td>
            @else
                <td>Due</td><td class="num">{{ $money($invoice->due_amount) }}</td>
            @endif
        </tr>
    </table>

    <footer>
        <span>Printed {{ now($tz)->format('d M Y, h:i A') }}</span>
        <span>Authorised signature: ____________________</span>
    </footer>
</div>
</body>
</html>
