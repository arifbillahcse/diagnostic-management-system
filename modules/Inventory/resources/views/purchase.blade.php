@php
    $purchase = $this->record->loadMissing(['supplier', 'lines.item', 'creator', 'payer']);
    $tz = app(\App\Core\Tenancy\ClinicContext::class)->timezone();
    $money = fn ($amount) => '৳ '.$amount->formatted();
    $state = $purchase->state();
@endphp
<x-filament-panels::page>
    <div class="dms-grid-2">
        <x-filament::section heading="Purchase">
            <dl class="dms-facts">
                <dt>Supplier</dt><dd>{{ $purchase->supplier?->name }}</dd>
                <dt>Supplier's bill</dt><dd>{{ $purchase->supplier_bill_no ?? '-' }}</dd>
                <dt>Date</dt><dd>{{ $purchase->purchase_date->format('d M Y') }}</dd>
                <dt>Entered by</dt><dd>{{ $purchase->creator?->name ?? '-' }}</dd>
                @if ($purchase->note)<dt>Note</dt><dd>{{ $purchase->note }}</dd>@endif
            </dl>
        </x-filament::section>
        <x-filament::section heading="Payment">
            <dl class="dms-facts">
                <dt>Total</dt><dd class="dms-strong">{{ $money($purchase->total) }}</dd>
                <dt>Status</dt>
                <dd><x-filament::badge :color="['void' => 'gray', 'paid' => 'success', 'unpaid' => 'danger'][$state]">{{ ['void' => 'Void', 'paid' => 'Paid', 'unpaid' => 'Unpaid'][$state] }}</x-filament::badge></dd>
                @if ($purchase->isPaid())
                    <dt>Paid on</dt><dd>{{ $purchase->paid_at->timezone($tz)->format('d M Y') }} by {{ $purchase->payer?->name ?? '-' }}</dd>
                    @if ($purchase->expense_id)<dt>Accounts</dt><dd>Recorded as an expense</dd>@endif
                    @if ($purchase->payment_note)<dt>Note</dt><dd>{{ $purchase->payment_note }}</dd>@endif
                @endif
                @if ($purchase->isVoid())
                    <dt>Voided</dt><dd>{{ $purchase->voided_at->timezone($tz)->format('d M Y, h:i A') }}: {{ $purchase->void_reason }}</dd>
                @endif
            </dl>
        </x-filament::section>
    </div>

    <x-filament::section heading="Items received">
        <div class="dms-scroll">
            <table class="dms-table">
                <thead><tr><th>Item</th><th class="dms-num">Quantity</th><th class="dms-num">Unit cost</th><th class="dms-num">Amount</th><th>Batch</th><th>Expires</th></tr></thead>
                <tbody>
                    @foreach ($purchase->lines as $line)
                        <tr>
                            <td>{{ $line->item->code }} · {{ $line->item->name }}</td>
                            <td class="dms-num">{{ $line->quantity->formatted() }} {{ $line->item->unit }}</td>
                            <td class="dms-num">{{ $money($line->unit_cost) }}</td>
                            <td class="dms-num">{{ $money($line->line_total) }}</td>
                            <td>{{ $line->batch_no ?? '-' }}</td>
                            <td>{{ $line->expires_on?->format('d M Y') ?? '-' }}</td>
                        </tr>
                    @endforeach
                    <tr class="dms-total"><td colspan="3">Total</td><td class="dms-num">{{ $money($purchase->total) }}</td><td colspan="2"></td></tr>
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
