<x-filament-panels::page>
    <x-filament::section>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <x-filament::input.wrapper>
                <x-filament::input type="month" wire:model.live="month" />
            </x-filament::input.wrapper>
            @if (count($this->branches) > 1)
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="branchId">
                        <option value="">All branches</option>
                        @foreach ($this->branches as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            @endif
        </div>
    </x-filament::section>

    <x-filament::section>
        @php($report = $this->report)
        @if ($report['rows'] === [])
            <p class="dms-muted">No staff worked in this month.</p>
        @else
            <div class="dms-scroll">
                <table class="dms-table" style="font-size:0.85em">
                    <thead>
                        <tr>
                            <th>Staff</th>
                            @foreach ($report['days'] as $day)
                                <th style="text-align:center;padding:2px 3px">{{ (int) substr($day, 8) }}</th>
                            @endforeach
                            <th class="dms-num" title="Days worked (half day counts half)">Worked</th>
                            <th class="dms-num">Absent</th>
                            <th class="dms-num">Leave</th>
                            <th class="dms-num" title="Weekly off and holidays">Off</th>
                            <th class="dms-num" title="Days up to today with no mark">Not marked</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report['rows'] as $row)
                            <tr>
                                <td style="white-space:nowrap">{{ $row['staff']->name }}<div class="dms-small">{{ $row['staff']->staff_no }}</div></td>
                                @foreach ($row['marks'] as $mark)
                                    <td style="text-align:center;vertical-align:middle;padding:2px 3px;{{ $mark === 'absent' ? 'color:#dc2626;font-weight:600' : '' }}">{{ $mark === null ? '·' : (\Modules\People\Enums::SHORT[$mark] ?? '') }}</td>
                                @endforeach
                                <td class="dms-num">{{ rtrim(rtrim(number_format($row['totals']['worked'], 1), '0'), '.') }}</td>
                                <td class="dms-num">{{ $row['totals']['absent'] }}</td>
                                <td class="dms-num">{{ $row['totals']['leave'] }}</td>
                                <td class="dms-num">{{ $row['totals']['off'] + $row['totals']['holiday'] }}</td>
                                <td class="dms-num">{{ $row['totals']['unmarked'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="dms-small" style="margin-top:8px">
                {{ collect(\Modules\People\Enums::SHORT)->map(fn ($short, $key) => $short.' '.\Modules\People\Enums::status($key))->implode(' · ') }}.
                A dot means not employed that day.
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
