@php
    $clinic = app(\App\Core\Tenancy\ClinicContext::class)->get()?->loadMissing('plan');
    $licence = app(\App\Core\Tenancy\Licence::class);
    $end = $clinic ? $licence->endsOn($clinic) : null;
    $how = config('dms.platform.billing.how_to_pay');
@endphp
<x-filament-panels::page>
    <x-filament::section heading="Your plan">
        <dl class="dms-facts">
            <dt>Plan</dt><dd>{{ $clinic?->plan?->name ?? '-' }} @if ($clinic?->plan?->description)<span class="dms-small">· {{ $clinic->plan->description }}</span>@endif</dd>
            <dt>Status</dt><dd>{{ $clinic?->status->label() }}</dd>
            <dt>Covered until</dt><dd>{{ $end?->format('d M Y') ?? 'No end date' }}</dd>
            @if ($clinic?->plan)
                <dt>Price</dt><dd>৳ {{ $clinic->plan->price_monthly->formatted() }} a month, or ৳ {{ $clinic->plan->price_yearly->formatted() }} a year · {{ $clinic->plan->branchLimitLabel() }}</dd>
            @endif
        </dl>
        @if ($how)<p class="dms-small" style="margin-top:8px"><strong>How to pay:</strong> {{ $how }}</p>@endif
        <p class="dms-small">To change your plan, contact {{ config('dms.platform.billing.name') }}@if (config('dms.platform.billing.phone')) at {{ config('dms.platform.billing.phone') }}@endif.</p>
    </x-filament::section>

    <x-filament::section heading="Invoices">
        @if ($this->invoices->isEmpty())
            <p class="dms-muted">No invoices yet.</p>
        @else
            <table class="dms-table">
                <thead><tr><th>Invoice</th><th>Period</th><th class="dms-num">Amount</th><th>Status</th><th></th></tr></thead>
                <tbody>
                    @foreach ($this->invoices as $invoice)
                        <tr>
                            <td>{{ $invoice->invoice_no }}</td>
                            <td>{{ $invoice->period_start->format('d M Y') }} to {{ $invoice->period_end->format('d M Y') }}</td>
                            <td class="dms-num">৳ {{ $invoice->amount->formatted() }}</td>
                            <td><x-filament::badge :color="\App\Platform\Billing\SubscriptionInvoice::color($invoice->status)">{{ \App\Platform\Billing\SubscriptionInvoice::STATUSES[$invoice->status] }}</x-filament::badge>@if ($invoice->status === 'issued') <span class="dms-small">due {{ $invoice->due_on->format('d M Y') }}</span>@endif</td>
                            <td class="dms-num"><x-filament::link :href="route('subscription.invoice.print', $invoice->public_id)" target="_blank">Print</x-filament::link></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
