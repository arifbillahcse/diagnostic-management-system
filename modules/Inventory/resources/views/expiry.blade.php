<x-filament-panels::page>
    <x-filament::section>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            @foreach (['soon' => 'Expired or expiring within '.\Modules\Inventory\Enums::EXPIRY_WARNING_DAYS.' days', 'expired' => 'Expired only', 'all' => 'Every batch in stock'] as $key => $label)
                <x-filament::button size="sm" :color="$show === $key ? 'primary' : 'gray'" wire:click="$set('show', '{{ $key }}')">{{ $label }}</x-filament::button>
            @endforeach
        </div>
    </x-filament::section>

    <x-filament::section>
        @if ($this->rows === [])
            <p class="dms-muted">Nothing to show.</p>
        @else
            <div class="dms-scroll">
                <table class="dms-table">
                    <thead><tr><th>Item</th><th>Batch</th><th>Expires</th><th class="dms-num">In stock</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($this->rows as $row)
                            <tr>
                                <td>{{ $row['code'] }} · {{ $row['item'] }}</td>
                                <td>{{ $row['batch_no'] }}</td>
                                <td>{{ \Carbon\Carbon::parse($row['expires_on'])->format('d M Y') }}</td>
                                <td class="dms-num">{{ $row['quantity']->formatted() }} {{ $row['unit'] }}</td>
                                <td>
                                    @if ($row['days'] < 0)
                                        <x-filament::badge color="danger">Expired {{ abs($row['days']) }} day(s) ago</x-filament::badge>
                                    @elseif ($row['days'] <= \Modules\Inventory\Enums::EXPIRY_WARNING_DAYS)
                                        <x-filament::badge color="warning">{{ $row['days'] === 0 ? 'Expires today' : "In {$row['days']} day(s)" }}</x-filament::badge>
                                    @else
                                        <x-filament::badge color="gray">In {{ $row['days'] }} days</x-filament::badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="dms-small" style="margin-top:8px">Expired stock is taken out from the Stock list: Take out, "Expired, thrown away".</p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
