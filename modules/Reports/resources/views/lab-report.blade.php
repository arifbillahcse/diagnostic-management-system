@php
    use Modules\Laboratory\Enums\ResultType;

    $fmt = fn ($time) => $time?->timezone($timezone)->format('d M Y, h:i A') ?? '-';
    $firstSample = $items->pluck('sample')->filter()->sortBy('collected_at')->first();
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Report {{ $order->order_no }}</title>
    <style>
        @page { margin: {{ $layout['top_mm'] }}mm 13mm {{ $layout['bottom_mm'] + 10 }}mm 13mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #111; }
        table { width: 100%; border-collapse: collapse; }

        .letterhead { border-bottom: 1.5pt solid #111; padding-bottom: 6pt; margin-bottom: 8pt; }
        .letterhead td { vertical-align: middle; }
        .letterhead .logo { width: 62pt; }
        .letterhead img { max-width: 56pt; max-height: 56pt; }
        .clinic { font-size: 16pt; font-weight: bold; }
        .small { font-size: 8.5pt; }
        .muted { color: #444; }

        .patient { border: 0.75pt solid #777; margin-bottom: 10pt; }
        .patient td { padding: 3pt 6pt; font-size: 9pt; width: 50%; }
        .label { color: #444; }

        .department { text-align: center; font-size: 11pt; font-weight: bold; letter-spacing: 1pt; margin: 10pt 0 4pt; text-transform: uppercase; }
        .test { font-size: 10pt; font-weight: bold; margin: 8pt 0 2pt; }
        .results th { text-align: left; font-size: 8.5pt; border-bottom: 1pt solid #111; border-top: 1pt solid #111; padding: 3pt 4pt; }
        .results td { padding: 3pt 4pt; border-bottom: 0.4pt solid #bbb; vertical-align: top; }
        .results .group td { font-weight: bold; font-size: 9pt; border-bottom: 0; padding-top: 6pt; }
        .abnormal { font-weight: bold; }
        .mark { font-weight: bold; font-size: 8pt; }
        .comment { font-size: 8.5pt; font-style: italic; color: #333; }
        .notes { font-size: 8.5pt; margin-top: 3pt; }
        .keep { page-break-inside: avoid; }

        .amended { border: 1pt solid #111; padding: 3pt 6pt; font-size: 8.5pt; margin-top: 3pt; }
        .waiting { font-size: 8.5pt; margin-top: 10pt; }
        .signers { margin-top: 26pt; page-break-inside: avoid; }
        .signers td { text-align: center; vertical-align: bottom; font-size: 8.5pt; padding: 0 8pt; }
        .signers img { max-height: 34pt; max-width: 110pt; }
        .signers .line { border-top: 0.75pt solid #111; padding-top: 2pt; font-weight: bold; font-size: 9pt; }

        .footer { position: fixed; left: 0; right: 0; bottom: -{{ 9 }}mm; height: 8mm; font-size: 7.5pt; color: #444; border-top: 0.4pt solid #999; padding-top: 2pt; }
        .footer td { vertical-align: top; }
        .page:after { content: counter(page); }
    </style>
</head>
<body>
    <div class="footer">
        <table><tr>
            <td>{{ $layout['footer'] }}</td>
            <td style="text-align: right; width: 58%; white-space: nowrap">{{ $order->order_no }} &middot; Printed {{ $printed_at->format('d M Y, h:i A') }} &middot; Page <span class="page"></span></td>
        </tr></table>
    </div>

    @unless ($layout['preprinted'])
        <table class="letterhead"><tr>
            @if ($clinic['logo'])
                <td class="logo"><img src="{{ $clinic['logo'] }}" alt=""></td>
            @endif
            <td>
                <div class="clinic">{{ $clinic['name'] }}</div>
                <div class="small">{{ $clinic['address'] }}</div>
                <div class="small">
                    {{ $clinic['phone'] }}@if ($clinic['phone'] && $clinic['email']) &middot; @endif{{ $clinic['email'] }}
                </div>
                @if ($clinic['header'])<div class="small muted">{!! nl2br(e($clinic['header'])) !!}</div>@endif
            </td>
        </tr></table>
    @endunless

    <table class="patient">
        <tr>
            <td><span class="label">Patient:</span> <strong>{{ $patient->name }}</strong></td>
            <td><span class="label">Patient ID:</span> {{ $patient->patient_code }}</td>
        </tr>
        <tr>
            <td><span class="label">Age / sex:</span> {{ $patient->ageLabel($firstSample?->collected_at ?? $order->ordered_at) }} / {{ $patient->sex->label() }}</td>
            <td><span class="label">Order:</span> {{ $order->order_no }}</td>
        </tr>
        <tr>
            <td><span class="label">Referred by:</span> {{ $order->referralDoctor?->name ?? 'Self' }}</td>
            <td><span class="label">Sample collected:</span> {{ $fmt($firstSample?->collected_at) }}</td>
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

                            @if ($result->result_type === ResultType::LongText)
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
</body>
</html>
