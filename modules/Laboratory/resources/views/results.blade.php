@php
    use Modules\Laboratory\Enums\ResultType;
    use Modules\Laboratory\Enums\WorkStatus;
    use Modules\Laboratory\Filament\Work\WorkItemResource;

    $item = $this->record->loadMissing(['patient', 'sample.sampleType', 'order', 'enterer', 'verifier']);
    $editable = $this->editable;
    $tz = 'Asia/Dhaka';
    $group = null;
@endphp

<x-filament-panels::page>
    <div class="dms-grid-2">
        <x-filament::section heading="Patient">
            <dl class="dms-facts">
                <dt>Name</dt><dd>{{ $item->patient->name }} ({{ $item->patient->patient_code }})</dd>
                <dt>Age / sex</dt>
                <dd class="dms-strong">{{ $item->patient->ageLabel($item->sample?->collected_at) }} / {{ $item->patient->sex->label() }}</dd>
                <dt>Order</dt><dd>{{ $item->order->order_no }}</dd>
            </dl>
        </x-filament::section>

        <x-filament::section heading="Sample {{ $item->sample?->accession_no }}">
            <dl class="dms-facts">
                <dt>Sample</dt><dd>{{ $item->sample?->sampleType?->name ?? '-' }}</dd>
                <dt>Collected</dt><dd>{{ $item->sample?->collected_at?->timezone($tz)->format('d M Y, h:i A') ?? '-' }}</dd>
                <dt>Status</dt>
                <dd><x-filament::badge :color="WorkItemResource::statusColor($item->status)">{{ $item->status->label() }}</x-filament::badge></dd>
                @if ($item->entered_by)
                    <dt>Entered by</dt><dd>{{ $item->enterer?->name }}, {{ $item->entered_at?->timezone($tz)->format('d M, h:i A') }}</dd>
                @endif
                @if ($item->version > 0)
                    <dt>Verified by</dt><dd>{{ $item->verifier?->name }}, {{ $item->verified_at?->timezone($tz)->format('d M, h:i A') }} (version {{ $item->version }})</dd>
                @endif
            </dl>
        </x-filament::section>
    </div>

    @if ($item->pending_reason)
        <x-filament::section>
            <p class="dms-alert dms-strong">
                {{ $item->status === WorkStatus::Amending || $item->version > 0 ? 'Being amended: ' : '' }}{{ $item->pending_reason }}
            </p>
        </x-filament::section>
    @endif

    <x-filament::section heading="Results" :description="$editable ? 'Flags are worked out by the system when you save. A value equal to a limit counts as normal.' : null">
        <form wire:submit="submit">
            <div class="dms-scroll">
                <table class="dms-table">
                    <thead>
                        <tr><th>Test</th><th style="min-width: 11rem">Result</th><th>Unit</th><th>Reference range</th><th>Flag</th><th style="min-width: 12rem">Comment</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($this->rows as $row)
                            @php
                                $analyte = $row['analyte'];
                                $result = $row['result'];
                                $field = "values.{$analyte->id}";
                            @endphp

                            @if ($row['group'] && $row['group'] !== $group)
                                @php $group = $row['group']; @endphp
                                <tr><td colspan="6" class="dms-strong">{{ $group }}</td></tr>
                            @endif

                            <tr wire:key="row-{{ $analyte->id }}">
                                <td>
                                    <label for="value-{{ $analyte->id }}">{{ $analyte->name }}</label>
                                    @unless ($row['required'])<span class="dms-small"> (optional)</span>@endunless
                                </td>
                                <td>
                                    @if ($editable)
                                        @if ($analyte->result_type === ResultType::Option)
                                            <x-filament::input.wrapper>
                                                <x-filament::input.select id="value-{{ $analyte->id }}" wire:model="{{ $field }}.value">
                                                    <option value="">Choose</option>
                                                    @foreach ($analyte->options ?? [] as $option)
                                                        <option value="{{ $option }}">{{ $option }}</option>
                                                    @endforeach
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>
                                        @elseif ($analyte->result_type === ResultType::LongText)
                                            <textarea id="value-{{ $analyte->id }}" wire:model="{{ $field }}.value" rows="3" class="dms-textarea"></textarea>
                                        @else
                                            <x-filament::input.wrapper>
                                                <x-filament::input
                                                    id="value-{{ $analyte->id }}"
                                                    type="text"
                                                    :inputmode="$analyte->result_type === ResultType::Numeric ? 'decimal' : 'text'"
                                                    autocomplete="off"
                                                    wire:model="{{ $field }}.value"
                                                />
                                            </x-filament::input.wrapper>
                                        @endif
                                    @else
                                        <span @class(['dms-strong' => $result?->flag->isAbnormal()])>{!! nl2br(e($result?->value_text ?? '-')) !!}</span>
                                    @endif
                                </td>
                                <td>{{ $result?->unit ?? $analyte->unit }}</td>
                                <td>{{ $row['range'] ?? '-' }}</td>
                                <td>
                                    @if ($result?->flag->isAbnormal())
                                        <x-filament::badge :color="$result->flag->isCritical() ? 'danger' : 'warning'">{{ $result->flag->label() }}</x-filament::badge>
                                    @endif
                                </td>
                                <td>
                                    @if ($editable)
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="text" aria-label="Comment for {{ $analyte->name }}" wire:model="{{ $field }}.comment" maxlength="255" />
                                        </x-filament::input.wrapper>
                                    @else
                                        {{ $result?->comment }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($editable)
                <div class="dms-actions">
                    <x-filament::button type="submit">Submit for verification</x-filament::button>
                    <x-filament::button type="button" color="gray" wire:click="save">Save draft</x-filament::button>
                </div>
            @endif
        </form>
    </x-filament::section>

    @if ($item->versions->isNotEmpty())
        <x-filament::section heading="Verified versions" description="Every verified version is kept. An amendment adds a version; it never replaces one.">
            <div class="dms-scroll">
                <table class="dms-table">
                    <thead><tr><th>Version</th><th>Verified</th><th>Values</th><th>Reason for amendment</th></tr></thead>
                    <tbody>
                        @foreach ($item->versions as $version)
                            <tr>
                                <td>{{ $version->version }}</td>
                                <td>{{ $version->verified_at->timezone($tz)->format('d M Y, h:i A') }}</td>
                                <td>{{ collect($version->snapshot)->map(fn ($r) => $r['analyte'].': '.$r['value'])->implode('; ') }}</td>
                                <td>{{ $version->reason ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
