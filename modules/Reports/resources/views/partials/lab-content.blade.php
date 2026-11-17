{{-- The lab results part of a report: codes, patient block, results, signers.
     Used by the lab report and by the combined lab and imaging report. --}}
@php
    $fmt = fn ($time) => $time?->timezone($timezone)->format('d M Y, h:i A') ?? '-';
    $firstSample = $items->pluck('sample')->filter()->sortBy('collected_at')->first();
@endphp
    @if ($info['bill_barcode'] || $info['lab_barcode'])
        <table class="codes"><tr>
            <td>@if ($info['bill_barcode'])<img src="{{ $info['bill_barcode'] }}" alt="">@endif</td>
            <td style="text-align: right">@if ($info['lab_barcode'])<img src="{{ $info['lab_barcode'] }}" alt="">@endif</td>
        </tr></table>
    @endif

    <table class="patient">
        <tr>
            <td><span class="label">Bill ID:</span> {{ $info['bill_no'] ?? '-' }} &nbsp;&nbsp; <span class="label">Lab No:</span> {{ $info['lab_no'] ?: '-' }}</td>
            <td><span class="label">Delivery date:</span> {{ $fmt($info['delivery_at']) }}</td>
        </tr>
        <tr>
            <td><span class="label">UHID:</span> <strong>{{ $patient->patient_code }}</strong></td>
            <td><span class="label">Received date:</span> {{ $fmt($info['received_at']) }}</td>
        </tr>
        <tr>
            <td><span class="label">Patient name:</span> <strong>{{ $patient->name }}</strong></td>
            <td><span class="label">Age:</span> {{ $patient->ageLabel($firstSample?->collected_at ?? $order->ordered_at) }}</td>
        </tr>
        <tr>
            <td><span class="label">Consultant:</span> {{ $info['consultant'] ?? 'Self' }}</td>
            <td><span class="label">Sex:</span> {{ $patient->sex->label() }}</td>
        </tr>
        <tr>
            <td><span class="label">Specimen:</span> <strong>{{ $info['specimen'] ?: '-' }}</strong></td>
            <td><span class="label">Sample collected:</span> {{ $fmt($info['collected_at']) }}</td>
        </tr>
    </table>

    @foreach ($departments as $department => $tests)
        <div class="department">{{ $department }}</div>

        @foreach ($tests as $test)
            <div class="keep">
                <div class="test">{{ $test->test_name }}</div>
                <div class="small muted">
                    Sample: {{ $test->sample?->sampleType?->name ?? '-' }} ({{ $test->sample?->accession_no ?? '-' }})
                    @if ($test->labTest?->method) &middot; Method: {{ $test->labTest->method }} @endif
                    &middot; Reported: {{ $fmt($test->released_at) }}
                </div>

                <table class="results">
                    <thead><tr>
                        <th style="width: 38%">Test</th><th style="width: 22%">Result</th><th style="width: 14%">Unit</th><th>Reference range</th>
                    </tr></thead>
                    <tbody>
                        @php $group = null; @endphp
                        @foreach ($test->results as $result)
                            @if ($result->group_label && $result->group_label !== $group)
                                @php $group = $result->group_label; @endphp
                                <tr class="group"><td colspan="4">{{ $group }}</td></tr>
                            @endif

                            @if ($result->result_type === \Modules\Laboratory\Enums\ResultType::LongText)
                                <tr><td>{{ $result->analyte_name }}</td><td colspan="3">{!! nl2br(e($result->value_text)) !!}</td></tr>
                            @else
                                <tr>
                                    <td>
                                        {{ $result->analyte_name }}
                                        @if ($result->comment)<div class="comment">{{ $result->comment }}</div>@endif
                                    </td>
                                    <td @class(['abnormal' => $result->flag->isAbnormal()])>
                                        {{ $result->value_text }}
                                        @if ($result->flag->isAbnormal())<span class="mark">&nbsp;{{ $result->flag->mark() }}</span>@endif
                                    </td>
                                    <td>{{ $result->unit }}</td>
                                    <td>{{ $result->ref_text }}</td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>

                @if ($test->labTest?->report_notes)
                    <div class="notes">{!! nl2br(e($test->labTest->report_notes)) !!}</div>
                @endif

                @if ($test->version > 1)
                    <div class="amended">
                        <strong>AMENDED REPORT</strong> (version {{ $test->version }}, {{ $fmt($test->verified_at) }}).
                        This replaces the report issued earlier for this test.
                    </div>
                @endif
            </div>
        @endforeach
    @endforeach

    <div class="small muted" style="margin-top: 8pt">H = high, L = low, HH / LL = critical, A = abnormal. Values in bold are outside the reference range.</div>

    @if ($waiting !== [])
        <div class="waiting"><strong>Not yet reported:</strong> {{ implode(', ', $waiting) }}.</div>
    @endif

    {{-- Signers sit at the right, a third of the page each, however many there are. --}}
    <table class="signers"><tr>
        @for ($i = count($signers); $i < 3; $i++)<td style="width: 33%"></td>@endfor
        @foreach ($signers as $signer)
            <td style="width: 33%">
                @if ($signer['signature'])<img src="{{ $signer['signature'] }}" alt=""><br>@else<br><br>@endif
                <div class="line">{{ $signer['name'] }}</div>
                @foreach ($signer['lines'] as $line)<div>{{ $line }}</div>@endforeach
            </td>
        @endforeach
    </tr></table>
