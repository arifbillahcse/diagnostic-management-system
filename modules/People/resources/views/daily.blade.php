<x-filament-panels::page>
    <x-filament::section>
        <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center">
            <x-filament::button size="sm" color="gray" wire:click="previousDay">‹ Day before</x-filament::button>
            <x-filament::input.wrapper>
                <x-filament::input type="date" wire:model.live="date" :max="$this->today()" />
            </x-filament::input.wrapper>
            <x-filament::button size="sm" color="gray" wire:click="nextDay" :disabled="$date >= $this->today()">Day after ›</x-filament::button>
            @if (count($this->branches) > 1)
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="branchId">
                        @foreach ($this->branches as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            @endif
            <span class="dms-muted">{{ \Carbon\Carbon::parse($date)->format('l, d M Y') }}</span>
        </div>
    </x-filament::section>

    <x-filament::section>
        @if ($this->staff->isEmpty())
            <p class="dms-muted">No staff worked in this branch on this day. Add staff from the Staff list.</p>
        @else
            @php($canRecord = $this->canRecord())
            <div class="dms-scroll">
                <table class="dms-table">
                    <thead><tr><th>Staff</th><th>Mark</th><th>In</th><th>Out</th><th>Note</th></tr></thead>
                    <tbody>
                        @foreach ($this->staff as $person)
                            <tr wire:key="row-{{ $person->id }}">
                                <td>{{ $person->name }}<div class="dms-small">{{ $person->staff_no }} · {{ $person->designation }}</div></td>
                                <td>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select wire:model="rows.{{ $person->id }}.status" :disabled="! $canRecord">
                                            <option value="">Not marked</option>
                                            @foreach (\Modules\People\Enums::STATUSES as $key => $label)
                                                <option value="{{ $key }}">{{ $label }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                </td>
                                <td><x-filament::input.wrapper><x-filament::input type="time" wire:model="rows.{{ $person->id }}.in_time" :disabled="! $canRecord" /></x-filament::input.wrapper></td>
                                <td><x-filament::input.wrapper><x-filament::input type="time" wire:model="rows.{{ $person->id }}.out_time" :disabled="! $canRecord" /></x-filament::input.wrapper></td>
                                <td><x-filament::input.wrapper><x-filament::input type="text" maxlength="255" wire:model="rows.{{ $person->id }}.note" :disabled="! $canRecord" /></x-filament::input.wrapper></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($canRecord)
                <div style="display:flex;gap:8px;margin-top:12px">
                    <x-filament::button wire:click="save">Save attendance</x-filament::button>
                    <x-filament::button color="gray" wire:click="markAllPresent">Mark the rest present</x-filament::button>
                </div>
                <p class="dms-small" style="margin-top:8px">Times are optional. A saved mark can be changed later but not removed; every change is recorded.</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
