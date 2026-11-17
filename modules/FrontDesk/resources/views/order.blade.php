@php
    use Modules\FrontDesk\Enums\ItemStatus;
    use Modules\FrontDesk\Enums\RefundStatus;
    use Modules\FrontDesk\Filament\Orders\OrderResource;

    $order = $this->record->loadMissing(['patient', 'referralDoctor', 'items', 'creator']);
    $invoice = $this->invoice;
    $tz = app(\App\Core\Tenancy\ClinicContext::class)->timezone();
    $money = fn ($amount) => '৳ '.$amount->formatted();
@endphp

<x-filament-panels::page>
    <div class="dms-grid-2">
        <x-filament::section heading="Patient">
            <dl class="dms-facts">
                <dt>Name</dt><dd>{{ $order->patient->name }}</dd>
                <dt>Code</dt><dd>{{ $order->patient->patient_code }}</dd>
                <dt>Age / sex</dt><dd>{{ $order->patient->ageLabel($order->ordered_at) }} / {{ $order->patient->sex->label() }}</dd>
                <dt>Phone</dt><dd>{{ $order->patient->phone ?? '-' }}</dd>
                <dt>Referred by</dt><dd>@if ($order->referralDoctor){{ $order->referralDoctor->code ? $order->referralDoctor->code.' · ' : '' }}{{ $order->referralDoctor->name }}@else - @endif</dd>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Invoice {{ $invoice->invoice_no }}">
            <dl class="dms-facts">
                <dt>Date</dt><dd>{{ $order->ordered_at->timezone($tz)->format('d M Y, h:i A') }}</dd>
                <dt>Report delivery</dt><dd>{{ $order->delivery_at?->timezone($tz)->format('d M Y, h:i A') ?? '-' }}</dd>
                <dt>Entered by</dt><dd>{{ $order->creator?->name ?? '-' }}</dd>
                <dt>Order status</dt><dd><x-filament::badge color="gray">{{ $order->status->label() }}</x-filament::badge></dd>
                <dt>Payment</dt>
                <dd><x-filament::badge :color="OrderResource::invoiceStatusColor($invoice->status)">{{ $invoice->status->label() }}</x-filament::badge></dd>
                <dt>Total</dt><dd>{{ $money($invoice->total) }}</dd>
                <dt>Paid</dt><dd>{{ $money($invoice->netPaid()) }}</dd>
                @if ($invoice->credit()->isPositive())
                    <dt>To refund</dt><dd class="dms-strong dms-alert">{{ $money($invoice->credit()) }}</dd>
                @else
                    <dt>Due</dt><dd class="dms-strong">{{ $money($invoice->due_amount) }}</dd>
                @endif
            </dl>
            @if ($order->notes)
                <p class="dms-muted">Remarks: {{ $order->notes }}</p>
            @endif
            @if ($order->cancel_reason)
                <p class="dms-muted">Cancelled: {{ $order->cancel_reason }}</p>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section heading="Tests and services">
        <div class="dms-scroll">
            <table class="dms-table">
                <thead><tr><th>Test</th><th>Status</th><th class="dms-num">Price</th><th class="dms-num">Discount</th><th class="dms-num">Net</th></tr></thead>
                <tbody>
                    @foreach ($order->items as $item)
                        <tr @class(['dms-struck' => $item->status === ItemStatus::Cancelled])>
                            <td>{{ $item->isPackageComponent() ? '↳ ' : '' }}{{ $item->service_name }}</td>
                            <td>{{ $item->status->label() }}@if ($item->cancel_reason): {{ $item->cancel_reason }}@endif</td>
                            @if ($item->isPackageComponent())
                                <td class="dms-num" colspan="3">In package</td>
                            @else
                                <td class="dms-num">{{ $money($item->unit_price) }}</td>
                                <td class="dms-num">{{ $money($item->discount_amount) }}</td>
                                <td class="dms-num">{{ $money($item->net_amount) }}</td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    @if ($this->reports !== [])
        <x-filament::section heading="Reports">
            <table class="dms-table">
                <tbody>
                    @foreach ($this->reports as $report)
                        <tr>
                            <td>{{ $report['label'] }}</td>
                            <td><x-filament::badge :color="$report['color']">{{ $report['status'] }}</x-filament::badge></td>
                            <td class="dms-num">
                                @if (! empty($report['open_url']))
                                    <x-filament::button size="sm" color="gray" tag="a" :href="$report['open_url']">Open</x-filament::button>
                                @endif
                                @if ($report['url'])
                                    <x-filament::button size="sm" color="gray" tag="a" :href="$report['url']" target="_blank">Print</x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-filament::section>
    @endif

    <x-filament::section heading="Invoice lines" description="Lines are never edited. A change after issue appears as its own line.">
        <div class="dms-scroll">
            <table class="dms-table">
                <thead><tr><th>Date</th><th>Line</th><th>Kind</th><th class="dms-num">Amount</th></tr></thead>
                <tbody>
                    @foreach ($invoice->lines as $line)
                        <tr>
                            <td>{{ $line->created_at->timezone($tz)->format('d M, h:i A') }}</td>
                            <td>{{ $line->description }}</td>
                            <td>{{ $line->kind->label() }}</td>
                            <td class="dms-num">{{ $money($line->amount) }}</td>
                        </tr>
                    @endforeach
                    <tr class="dms-total"><td colspan="3">Total</td><td class="dms-num">{{ $money($invoice->total) }}</td></tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>

    <x-filament::section heading="Payments">
        @if ($invoice->payments->isEmpty())
            <p class="dms-muted">No payment yet.</p>
        @else
            <div class="dms-scroll">
                <table class="dms-table">
                    <thead><tr><th>Receipt</th><th>Date</th><th>Method</th><th>Received by</th><th class="dms-num">Amount</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($invoice->payments as $payment)
                            <tr>
                                <td>{{ $payment->receipt_no }}</td>
                                <td>{{ $payment->received_at->timezone($tz)->format('d M Y, h:i A') }}</td>
                                <td>{{ $payment->method->label() }}@if ($payment->reference) ({{ $payment->reference }})@endif</td>
                                <td>{{ $payment->receiver?->name ?? '-' }}</td>
                                <td class="dms-num">{{ $money($payment->amount) }}</td>
                                <td class="dms-num"><a class="dms-link" target="_blank" href="{{ route('frontdesk.print.receipt', $payment->public_id) }}">Print receipt</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    @if ($invoice->refunds->isNotEmpty())
        <x-filament::section heading="Refunds">
            <div class="dms-scroll">
                <table class="dms-table">
                    <thead><tr><th>Refund</th><th>Requested</th><th>Reason</th><th>Status</th><th class="dms-num">Amount</th></tr></thead>
                    <tbody>
                        @foreach ($invoice->refunds as $refund)
                            <tr>
                                <td>{{ $refund->refund_no ?? '-' }}</td>
                                <td>{{ $refund->requested_at->timezone($tz)->format('d M, h:i A') }} by {{ $refund->requester?->name ?? '-' }}</td>
                                <td>{{ $refund->reason }}</td>
                                <td>
                                    {{ $refund->status->label() }}
                                    @if ($refund->status !== RefundStatus::Pending) by {{ $refund->decider?->name ?? '-' }} @endif
                                </td>
                                <td class="dms-num">{{ $money($refund->amount) }} ({{ $refund->method->label() }})</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
