@php
    use Modules\Owner\Filament\SalesReportsPage;

    $table = $this->table;
@endphp

<x-filament-panels::page>
    <x-filament::section>
        <div class="dms-filters">
            <label>Report
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="report">
                        @foreach (SalesReportsPage::REPORTS as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label>From
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="from" /></x-filament::input.wrapper>
            </label>
            <label>To
                <x-filament::input.wrapper><x-filament::input type="date" wire:model.live="to" /></x-filament::input.wrapper>
            </label>
        </div>
    </x-filament::section>

    <x-filament::section :heading="SalesReportsPage::REPORTS[$this->report] ?? 'Report'" description="All branches. Amounts in taka. Cancelled orders and cancelled tests are left out.">
        @if ($table['error'])
            <p class="dms-alert dms-strong">{{ $table['error'] }}</p>
        @elseif ($table['rows'] === [])
            <p class="dms-muted">Nothing in this period.</p>
        @else
            <div class="dms-scroll">
                <table class="dms-table">
                    <thead>
                        <tr>
                            @foreach ($table['headings'] as $i => $heading)
                                <th @class(['dms-num' => $i >= $table['text']])>{{ $heading }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($table['rows'] as $row)
                            <tr>
                                @foreach ($row as $i => $cell)
                                    <td @class(['dms-num' => $i >= $table['text']])>{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                        <tr class="dms-total">
                            @foreach ($table['totals'] as $i => $cell)
                                <td @class(['dms-num' => $i >= $table['text']])>{{ $cell }}</td>
                            @endforeach
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
