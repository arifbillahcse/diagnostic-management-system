<x-filament-panels::page>
    <x-filament::section>
        <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:end">
            <label style="display:grid;gap:4px"><span class="dms-small">From</span><input type="date" wire:model.live="from" class="fi-input" style="border:1px solid #C4D4CD;border-radius:8px;padding:6px 10px"></label>
            <label style="display:grid;gap:4px"><span class="dms-small">To</span><input type="date" wire:model.live="to" class="fi-input" style="border:1px solid #C4D4CD;border-radius:8px;padding:6px 10px"></label>
            <span class="dms-small">Up to a year at a time. Cancelled tests are taken off.</span>
        </div>
    </x-filament::section>

    @if ($this->byTest === null)
        <x-filament::section><p class="dms-alert">Choose a "from" date before the "to" date, at most a year apart.</p></x-filament::section>
    @else
        <div class="dms-grid-2">
            <x-filament::section heading="Cost of stock per test">
                @if ($this->byTest === [])
                    <p class="dms-muted">No stock was used by tests in this period. Set up "Stock use per test" to track it.</p>
                @else
                    <table class="dms-table">
                        <thead><tr><th>Test</th><th class="dms-num">Times</th><th class="dms-num">Stock used</th><th class="dms-num">Per test</th></tr></thead>
                        <tbody>
                            @foreach ($this->byTest as $row)
                                <tr>
                                    <td>{{ $row['test'] }}</td>
                                    <td class="dms-num">{{ number_format($row['times']) }}</td>
                                    <td class="dms-num">৳ {{ $row['value']->formatted() }}</td>
                                    <td class="dms-num">৳ {{ $row['times'] > 0 ? \App\Core\Money\Money::fromMinor(intdiv($row['value']->minor, $row['times']))->formatted() : '0.00' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>

            <x-filament::section heading="Stock gone out, by item">
                @if ($this->byItem === [])
                    <p class="dms-muted">No stock went out in this period.</p>
                @else
                    <table class="dms-table">
                        <thead><tr><th>Item</th><th class="dms-num">For tests</th><th class="dms-num">Issued, wasted, expired</th><th class="dms-num">Value</th></tr></thead>
                        <tbody>
                            @foreach ($this->byItem as $row)
                                <tr>
                                    <td>{{ $row['item'] }}</td>
                                    <td class="dms-num">{{ $row['used']->formatted() }} {{ $row['unit'] }}</td>
                                    <td class="dms-num">{{ $row['other']->formatted() }} {{ $row['unit'] }}</td>
                                    <td class="dms-num">৳ {{ $row['value']->formatted() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        </div>
    @endif
</x-filament-panels::page>
