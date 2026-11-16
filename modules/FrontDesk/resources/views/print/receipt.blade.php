@php
    $tz = 'Asia/Dhaka';
    $invoice = $payment->invoice;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $payment->receipt_no }}</title>
    <style>
        /* Sized for an 80 mm receipt printer; also prints on A4. */
        @page { margin: 4mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 10.5pt; margin: 0; padding: 12px; }
        .slip { width: 72mm; margin: 0 auto; }
        .center { text-align: center; }
        h1 { font-size: 13pt; margin: 0 0 2px; }
        .sub { font-size: 9pt; }
        hr { border: 0; border-top: 1px dashed #111; margin: 6px 0; }
        .row { display: flex; justify-content: space-between; gap: 8px; margin: 2px 0; }
        .big { font-size: 13pt; font-weight: bold; }
        .actions { text-align: center; margin-bottom: 12px; }
        button { font-size: 12pt; padding: 8px 20px; cursor: pointer; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="slip">
    <div class="actions"><button type="button" onclick="window.print()">Print</button></div>

    <div class="center">
        <h1>{{ $clinic['name'] }}</h1>
        <div class="sub">{{ $clinic['address'] }}</div>
        <div class="sub">{{ $clinic['phone'] }}</div>
    </div>
    <hr>
    <div class="center"><strong>MONEY RECEIPT</strong></div>
    <div class="row"><span>Receipt</span><span>{{ $payment->receipt_no }}</span></div>
    <div class="row"><span>Date</span><span>{{ $payment->received_at->timezone($tz)->format('d M Y, h:i A') }}</span></div>
    <div class="row"><span>Invoice</span><span>{{ $invoice->invoice_no }}</span></div>
    <div class="row"><span>Patient</span><span>{{ $invoice->patient->name }}</span></div>
    <div class="row"><span>Code</span><span>{{ $invoice->patient->patient_code }}</span></div>
    <hr>
    <div class="row big"><span>Received</span><span>Tk {{ $payment->amount->formatted() }}</span></div>
    <div class="row"><span>Method</span><span>{{ $payment->method->label() }}</span></div>
    @if ($payment->reference)<div class="row"><span>Reference</span><span>{{ $payment->reference }}</span></div>@endif
    <hr>
    <div class="row"><span>Invoice total</span><span>{{ $invoice->total->formatted() }}</span></div>
    <div class="row"><span>Paid so far</span><span>{{ $invoice->netPaid()->formatted() }}</span></div>
    <div class="row"><strong>Due now</strong><strong>{{ $invoice->due_amount->isNegative() ? '0.00' : $invoice->due_amount->formatted() }}</strong></div>
    <hr>
    <div class="row"><span>Received by</span><span>{{ $payment->receiver?->name ?? '-' }}</span></div>
    <div class="center sub" style="margin-top: 8px">Thank you</div>
</div>
</body>
</html>
