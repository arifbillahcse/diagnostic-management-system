@php
    use Modules\FrontDesk\Enums\InvoiceStatus;
    use Modules\FrontDesk\Enums\LineKind;
    use Modules\FrontDesk\Enums\RefundStatus;

    $money = fn ($amount) => $amount->formatted();
    $order = $invoice->order;
    $doctor = $order->referralDoctor;
    $rows = $invoice->lines->filter(fn ($line) => $line->kind !== LineKind::Discount)->values();
    $gross = $invoice->subtotal->plus($invoice->adjustment_total);
    $refunded = $invoice->refunds->where('status', RefundStatus::Approved)->isNotEmpty();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->invoice_no }}</title>
    <style>
        @page { size: A5 portrait; margin: 8mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 9pt; margin: 0; padding: 12px; }
        .sheet { width: 132mm; margin: 0 auto; }
        header { display: flex; gap: 10px; align-items: center; border-bottom: 1.5px solid #111; padding-bottom: 6px; margin-bottom: 6px; }
        header img { height: 16mm; width: auto; max-width: 22mm; object-fit: contain; }
        header .text { flex: 1; text-align: center; }
        h1 { font-size: 13pt; margin: 0 0 2px; }
        .sub { font-size: 8pt; line-height: 1.35; white-space: pre-line; }
        .title { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
        .title .name { text-align: center; flex: 1; }
        .title strong { font-size: 12pt; letter-spacing: .06em; display: block; }
        .barcode svg { width: 38mm; height: 9mm; display: block; }
        .box { border: 1px solid #111; padding: 4px 6px; margin-bottom: 6px; }
        .grid { display: grid; grid-template-columns: 1.3fr 1fr; gap: 2px 12px; }
        .grid .wide { grid-column: 1 / -1; }
        .lbl { display: inline-block; min-width: 17mm; color: #444; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 3px; text-align: left; vertical-align: top; }
        thead th { border-top: 1px solid #111; border-bottom: 1px solid #111; font-size: 8.5pt; }
        tbody td { border-bottom: 1px dotted #aaa; }
        tr { page-break-inside: avoid; }
        .num { text-align: right; white-space: nowrap; }
        .remarks { margin: 4px 0; font-size: 8.5pt; }
        .middle { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; margin-top: 6px; }
        .totals { width: 55mm; }
        .totals td { padding: 2px 3px; }
        .totals .strong td { font-weight: bold; border-top: 1px solid #111; }
        .pay { margin-top: 6px; font-size: 8pt; }
        .pay th { border-bottom: 1px solid #111; font-size: 8pt; }
        .words { margin-top: 8px; font-size: 9pt; }
        .rooms { margin-top: 4px; font-size: 9pt; font-weight: bold; }
        .void { text-align: center; font-weight: bold; border: 2px solid #111; padding: 4px; margin-bottom: 6px; }
        footer { margin-top: 14px; font-size: 8pt; display: flex; justify-content: space-between; align-items: flex-end; }
        .actions { text-align: center; margin: 0 0 10px; }
        button, .actions a { font-size: 11pt; padding: 6px 16px; cursor: pointer; margin: 0 4px; }
        .page + .page { page-break-before: always; margin-top: 24px; }
        @media print { .actions { display: none; } body { padding: 0; } .page + .page { margin-top: 0; } }
    </style>
</head>
<body>
<div class="sheet">
    <div class="actions">
        <button type="button" onclick="window.print()">Print</button>
        <a href="?copy=both">Both copies</a>
        <a href="?copy=office">Office copy</a>
        <a href="?">Patient copy</a>
    </div>
    @foreach ($copies as $copy)
    <div class="page">



        <header>
            @if ($clinic['logo'])<img src="{{ $clinic['logo'] }}" alt="">@endif
            <div class="text">
                <h1>{{ $clinic['name'] }}</h1>
                <div class="sub">{{ $clinic['address'] }}</div>
                <div class="sub">
                    @if ($clinic['phone'])Mobile: {{ $clinic['phone'] }}@endif
                    @if ($clinic['email'])&nbsp; Email: {{ $clinic['email'] }}@endif
                </div>
                @if ($clinic['header'])<div class="sub">{{ $clinic['header'] }}</div>@endif
            </div>
        </header>

        <div class="title">
            <div class="barcode">{!! $barcode !!}</div>
            <div class="name"><strong>INVOICE</strong><span class="sub">({{ $copy }})</span></div>
            <div style="width: 38mm"></div>
        </div>

        @if ($invoice->isVoid())
            <div class="void">VOID: {{ $invoice->void_reason }}</div>
        @endif

        <div class="box grid">
            <div><span class="lbl">Bill No</span>: <strong>{{ $invoice->invoice_no }}</strong></div>
            <div><span class="lbl">Date &amp; time</span>: {{ $invoice->issued_at->timezone($tz)->format('d-M-Y h:i A') }}</div>
            <div><span class="lbl">UHID</span>: <strong>{{ $invoice->patient->patient_code }}</strong></div>
            <div><span class="lbl">Gender</span>: {{ $invoice->patient->sex->label() }}</div>
            <div><span class="lbl">Name</span>: {{ $invoice->patient->name }}</div>
            <div><span class="lbl">Mobile</span>: {{ $invoice->patient->phone ?? '' }}</div>
            <div><span class="lbl">Age</span>: {{ $invoice->patient->ageLabel($invoice->issued_at) }}</div>
            @if ($order->delivery_at)
                <div><span class="lbl">Delivery</span>: {{ $order->delivery_at->timezone($tz)->format('d-M-Y h:i A') }}</div>
            @endif
            <div class="wide"><span class="lbl">Ref. by</span>: @if ($doctor)@if ($doctor->code){{ $doctor->code }}, @endif{{ $doctor->name }}@if ($doctor->degree), {{ $doctor->degree }}@endif @endif</div>
        </div>

        <table>
            <thead><tr><th style="width: 7%">SI</th><th>Test name</th><th class="num" style="width: 22%">Price</th></tr></thead>
            <tbody>
                @foreach ($rows as $i => $line)
                    <tr><td>{{ $i + 1 }}</td><td>{{ $line->description }}</td><td class="num">{{ $money($line->amount) }}</td></tr>
                @endforeach
            </tbody>
        </table>

        @if (filled($order->notes))
            <div class="remarks"><strong>Remarks:</strong> {{ $order->notes }}</div>
        @endif

        <div class="middle">
            <div></div>
            <table class="totals">
                <tr><td>Total Tk.</td><td class="num">{{ $money($gross) }}</td></tr>
                <tr><td>Discount Tk.</td><td class="num">{{ $money($invoice->discount_total) }}</td></tr>
                <tr class="strong"><td>Net payable</td><td class="num">{{ $money($invoice->total) }}</td></tr>
                <tr><td>Paid Tk.</td><td class="num">{{ $money($invoice->paid_total) }}</td></tr>
                @if ($refunded)
                    <tr><td>Refunded Tk.</td><td class="num">{{ $money($invoice->refunded_total) }}</td></tr>
                @endif
                <tr class="strong">
                    @if ($invoice->credit()->isPositive())
                        <td>To be refunded</td><td class="num">{{ $money($invoice->credit()) }}</td>
                    @else
                        <td>Due Tk.</td><td class="num">{{ $money($invoice->due_amount) }}</td>
                    @endif
                </tr>
            </table>
        </div>

        @if ($invoice->payments->isNotEmpty())
            <table class="pay">
                <thead><tr><th>Tran. type</th><th>User</th><th>Date &amp; time</th><th class="num">Amount</th></tr></thead>
                <tbody>
                    @foreach ($invoice->payments->sortBy('received_at')->values() as $i => $payment)
                        <tr>
                            <td>{{ $i === 0 ? 'Initial payment' : 'Due payment' }} ({{ $payment->method->label() }})</td>
                            <td>{{ $payment->receiver?->name ?? '-' }}</td>
                            <td>{{ $payment->received_at->timezone($tz)->format('d-M-Y h:i A') }}</td>
                            <td class="num">{{ $money($payment->amount) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if ($invoice->netPaid()->isPositive())
            <div class="words"><strong>Received with thanks:</strong> <em>{{ $words }}</em></div>
        @endif

        @if ($rooms !== [])
            <div class="rooms">
                @foreach ($rooms as $i => $room){{ $i + 1 }}. {{ $room }}&nbsp;&nbsp; @endforeach
            </div>
        @endif

        <footer>
            <span>Printed {{ now($tz)->format('d-M-Y h:i A') }}</span>
            <span>Signature by: <strong>{{ $order->creator?->name }}</strong></span>
        </footer>

    </div>
    @endforeach
</div>
@if ($autoprint)
<script>window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 300); });</script>
@endif
</body>
</html>
