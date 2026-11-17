@php
    $message = $this->record->loadMissing(['machine', 'results.analyte']);
    $tz = app(\App\Core\Tenancy\ClinicContext::class)->timezone();
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <dl class="dms-facts">
            <dt>Machine</dt><dd>{{ $message->machine?->name }} ({{ \Modules\Analyzers\Models\Machine::PROTOCOLS[$message->protocol] ?? $message->protocol }})</dd>
            <dt>Received</dt><dd>{{ $message->received_at->timezone($tz)->format('d M Y, h:i:s A') }}</dd>
            <dt>Sample</dt><dd>{{ $message->sample_codes ?? '-' }}</dd>
            <dt>Status</dt><dd><x-filament::badge :color="\Modules\Analyzers\Models\Message::color($message->status)">{{ \Modules\Analyzers\Models\Message::STATUSES[$message->status] ?? $message->status }}</x-filament::badge> @if ($message->note)<span class="dms-small">{{ $message->note }}</span>@endif</dd>
        </dl>
    </x-filament::section>

    @if ($message->results->isNotEmpty())
        <x-filament::section heading="Values">
            <div class="dms-scroll">
                <table class="dms-table">
                    <thead><tr><th>Sample</th><th>Machine code</th><th>Our analyte</th><th class="dms-num">Value</th><th>Unit</th><th>What happened</th></tr></thead>
                    <tbody>
                        @foreach ($message->results as $result)
                            <tr>
                                <td>{{ $result->sample_code ?? '-' }}</td>
                                <td>{{ $result->machine_code }}</td>
                                <td>{{ $result->analyte?->name ?? '-' }}</td>
                                <td class="dms-num">{{ $result->value }}</td>
                                <td>{{ $result->unit }}</td>
                                <td>
                                    <x-filament::badge :color="$result->status === 'applied' ? 'success' : 'warning'">{{ \Modules\Analyzers\Models\MachineResult::STATUSES[$result->status] ?? $result->status }}</x-filament::badge>
                                    @if ($result->note)<div class="dms-small">{{ $result->note }}</div>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="dms-small" style="margin-top:8px">Saved values are drafts: open the test in the lab work list to check them, submit, and verify as usual. "Code not mapped": add the code under Lab machines, Test codes.</p>
        </x-filament::section>
    @endif

    <x-filament::section heading="As received" collapsible collapsed>
        <pre style="white-space:pre-wrap;word-break:break-all;font-size:12px;margin:0">{{ str_replace(["\r", "\x02", "\x03", "\x05", "\x04", "\x06", "\x0b", "\x1c", "\x17"], ["\n", '<STX>', '<ETX>', '<ENQ>', '<EOT>', '<ACK>', '<VT>', '<FS>', '<ETB>'], $message->raw) }}</pre>
    </x-filament::section>
</x-filament-panels::page>
