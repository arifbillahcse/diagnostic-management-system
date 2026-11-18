@php($clinic = $invoice->clinic)
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Invoice {{ $invoice->invoice_no }}</title>
    <style>
        @page { size: A4; margin: 14mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 10.5pt; margin: 0; padding: 16px; }
        .top { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #1E1B4B; padding-bottom: 10px; margin-bottom: 14px; }
        h1 { font-size: 18pt; margin: 0; color: #1E1B4B; }
        .muted { color: #555; }
        table { width: 100%; border-collapse: collapse; margin: 12px 0; }
        th, td { border: 1px solid #bbb; padding: 6px 8px; text-align: left; }
        th { background: #f0f0f6; }
        .num { text-align: right; white-space: nowrap; }
        .total td { font-weight: bold; font-size: 12pt; }
        .stamp { display: inline-block; border: 2px solid; padding: 3px 10px; font-weight: bold; letter-spacing: 1px; }
        .paid { color: #1f7a4d; } .void { color: #b91c1c; } .issued { color: #a15c07; }
        .actions { text-align: center; margin-bottom: 12px; }
        button { font-size: 12pt; padding: 8px 20px; cursor: pointer; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Print</button></div>
    <div class="top">
        <div>
            <h1>{{ $seller['name'] }}</h1>
            @if ($seller['address'])<div class="muted">{{ $seller['address'] }}</div>@endif
            <div class="muted">{{ collect([$seller['phone'], $seller['email']])->filter()->implode(' · ') }}</div>
        </div>
        <div style="text-align:right">
            <div style="font-size:14pt;font-weight:bold">INVOICE</div>
            <div>{{ $invoice->invoice_no }}</div>
            <div class="muted">Issued {{ $invoice->created_at->timezone($clinic->timezone ?: 'Asia/Dhaka')->format('d M Y') }}</div>
            <div class="stamp {{ $invoice->status }}" style="margin-top:6px">{{ strtoupper(\App\Platform\Billing\SubscriptionInvoice::STATUSES[$invoice->status]) }}</div>
        </div>
    </div>

    <div><strong>Bill to:</strong> {{ $clinic->name }}<br><span class="muted">{{ $clinic->domains->pluck('domain')->implode(', ') }}</span></div>

    <table>
        <thead><tr><th>Description</th><th>Period</th><th class="num">Amount (৳)</th></tr></thead>
        <tbody>
            <tr>
                <td>DMS subscription: {{ $invoice->plan_name }} plan, {{ $invoice->billing_period === 'yearly' ? 'one year' : 'one month' }}@if ($invoice->note)<br><span class="muted">{{ $invoice->note }}</span>@endif</td>
                <td>{{ $invoice->period_start->format('d M Y') }} to {{ $invoice->period_end->format('d M Y') }}</td>
                <td class="num">{{ $invoice->amount->formatted() }}</td>
            </tr>
            <tr class="total"><td colspan="2">Total</td><td class="num">{{ $invoice->amount->formatted() }}</td></tr>
        </tbody>
    </table>
    <div class="muted">In words: {{ $words }}</div>

    @if ($invoice->status === 'paid')
        @foreach ($invoice->payments as $payment)
            <p><strong>Paid</strong> {{ $payment->received_on->format('d M Y') }} by {{ \App\Platform\Billing\SubscriptionPayment::METHODS[$payment->method] ?? $payment->method }}@if ($payment->reference) (ref. {{ $payment->reference }})@endif. Thank you.</p>
        @endforeach
    @elseif ($invoice->status === 'void')
        <p class="void"><strong>Void:</strong> {{ $invoice->void_reason }}</p>
    @else
        <p><strong>Due by {{ $invoice->due_on->format('d M Y') }}.</strong>@if ($seller['how_to_pay']) {{ $seller['how_to_pay'] }}@endif Please write the invoice number {{ $invoice->invoice_no }} as the payment reference.</p>
    @endif
</body>
</html>
