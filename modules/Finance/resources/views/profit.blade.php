@php
    use Modules\FrontDesk\Enums\PaymentMethod;

    $result = $this->result;
    $report = $result['report'];
    $money = fn ($amount) => '৳ '.$amount->formatted();
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <div class="dms-filters">
            <label>From
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
            </label>
            <label>To
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper>
            </label>
        </div>
    </x-filament::section>

    @if ($result['error'])
        <x-filament::section><p class="dms-alert dms-strong">{{ $result['error'] }}</p></x-filament::section>
    @else
        <div class="dms-grid-2">
            <x-filament::section heading="Money in and out" description="Cash basis: what was actually received and paid in this period.">
                <table class="dms-table">
                    <tbody>
                        <tr><td>Collected from patients</td><td class="dms-num">{{ $money($report['collected']) }}</td></tr>
                        <tr><td>Refunded to patients</td><td class="dms-num">- {{ $money($report['refunded']) }}</td></tr>
                        <tr><td>Expenses</td><td class="dms-num">- {{ $money($report['expenses']) }}</td></tr>
                        <tr><td>Commission paid to doctors</td><td class="dms-num">- {{ $money($report['commission_paid']) }}</td></tr>
                        <tr class="dms-total"><td>Left over</td><td @class(['dms-num', 'dms-alert' => $report['net']->isNegative()])>{{ $money($report['net']) }}</td></tr>
                    </tbody>
                </table>
            </x-filament::section>

            <x-filament::section heading="Not yet in the figures above">
                <table class="dms-table">
                    <tbody>
                        <tr><td>Billed in this period</td><td class="dms-num">{{ $money($report['billed']) }}</td></tr>
                        <tr><td>Commission earned in this period</td><td class="dms-num">{{ $money($report['commission_earned']) }}</td></tr>
                        <tr><td>Commission owed to doctors now</td><td class="dms-num">{{ $money($report['commission_owed']) }}</td></tr>
                        <tr><td>Dues owed by patients now</td><td class="dms-num">{{ $money($report['dues']) }}</td></tr>
                    </tbody>
                </table>
            </x-filament::section>
        </div>

        <div class="dms-grid-2">
            <x-filament::section heading="Expenses by category">
                @if ($report['by_category'] === [])
                    <p class="dms-muted">No expenses in this period.</p>
                @else
                    <table class="dms-table">
                        <tbody>
                            @foreach ($report['by_category'] as $row)
                                <tr><td>{{ $row['name'] }}</td><td class="dms-num">{{ $money($row['amount']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>

            <x-filament::section heading="Collections by payment method">
                @if ($report['by_method'] === [])
                    <p class="dms-muted">Nothing collected in this period.</p>
                @else
                    <table class="dms-table">
                        <tbody>
                            @foreach ($report['by_method'] as $row)
                                <tr><td>{{ PaymentMethod::from($row['method'])->label() }}</td><td class="dms-num">{{ $money($row['amount']) }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
