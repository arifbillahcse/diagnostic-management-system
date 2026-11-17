@php
    $fmt = fn ($time) => $time?->timezone($timezone)->format('d M Y, h:i A') ?? '-';
    $pageBottom = $layout['bottom_mm'] + 10; // the @page bottom margin, in mm
    $footerH = $layout['footer_image']['height_mm'] ?? 0;
    $sideShift = fn (array $banner): float => -13 + (210 - $banner['width_mm']) / 2;
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Imaging report {{ $order->order_no }}</title>
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

        .study { text-align: center; font-size: 12pt; font-weight: bold; letter-spacing: 0.5pt; margin: 10pt 0 8pt; text-transform: uppercase; text-decoration: underline; }
        .section { font-size: 10pt; font-weight: bold; margin: 10pt 0 3pt; text-transform: uppercase; }
        .text p { margin: 0 0 4pt; line-height: 1.35; }
        .text ul, .text ol { margin: 0 0 4pt 14pt; padding: 0; }
        .text li { margin-bottom: 2pt; }
        .text h2, .text h3, .text h4 { font-size: 10pt; margin: 6pt 0 2pt; }
        .text table { margin: 4pt 0; }
        .text td, .text th { border: 0.5pt solid #999; padding: 2pt 4pt; font-size: 9pt; text-align: left; }
        .impression { font-weight: bold; }
        .next { page-break-before: always; }
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

        .banner { position: fixed; width: 210mm; text-align: center; }
        .banner img { display: inline-block; }
        .watermark { position: fixed; top: 85mm; left: 40mm; width: 130mm; opacity: 0.07; }
        .codes td { padding: 0 0 3pt; font-size: 7.5pt; color: #444; }
        .codes img { height: 9mm; width: 55mm; }
        .footer { position: fixed; left: 0; right: 0; bottom: -{{ 9 }}mm; height: 8mm; font-size: 7.5pt; color: #444; border-top: 0.4pt solid #999; padding-top: 2pt; }
        .footer td { vertical-align: top; }
        .page:after { content: counter(page); }
    </style>
</head>
<body>
    @if ($layout['header_image'])
        <div class="banner" style="top: -{{ $layout['top_mm'] }}mm; left: {{ $sideShift($layout['header_image']) }}mm">
            <img src="{{ $layout['header_image']['uri'] }}" alt="" style="width: {{ $layout['header_image']['width_mm'] }}mm; height: {{ $layout['header_image']['height_mm'] }}mm">
        </div>
    @endif

    @if ($layout['footer_image'])
        <div class="banner" style="bottom: -{{ $pageBottom }}mm; left: {{ $sideShift($layout['footer_image']) }}mm">
            <img src="{{ $layout['footer_image']['uri'] }}" alt="" style="width: {{ $layout['footer_image']['width_mm'] }}mm; height: {{ $layout['footer_image']['height_mm'] }}mm">
        </div>
    @endif

    @if ($layout['watermark'])
        <img class="watermark" src="{{ $layout['watermark'] }}" alt="">
    @endif

    <div class="footer" @if ($layout['footer_image']) style="bottom: -{{ $pageBottom - $footerH - 2 }}mm" @endif>
        <table><tr>
            <td>{{ $layout['footer'] }}</td>
            <td style="text-align: right; width: 58%; white-space: nowrap">{{ $order->order_no }} &middot; Printed {{ $printed_at->format('d M Y, h:i A') }} &middot; Page <span class="page"></span></td>
        </tr></table>
    </div>

    @include('imaging::partials.letterhead')


    @isset($lab)
        @include('reports::partials.lab-content', $lab)
        <div class="next"></div>
    @endisset

    @foreach ($pages as $i => $page)
        @php($study = $page['study'])
        @php($version = $page['version'])
        <div @if ($i > 0) class="next" @endif>
            {{-- Every imaging page starts with the clinic header, also after lab results. --}}
            @if ($i > 0 || isset($lab))
                @include('imaging::partials.letterhead')
            @endif
            <table class="patient">
                <tr>
                    <td><span class="label">Bill ID:</span> {{ $bill_no ?? '-' }} &nbsp;&nbsp; <span class="label">UHID:</span> <strong>{{ $patient->patient_code }}</strong></td>
                    <td><span class="label">Date:</span> {{ $fmt($order->ordered_at) }}</td>
                </tr>
                <tr>
                    <td><span class="label">Patient name:</span> <strong>{{ $patient->name }}</strong></td>
                    <td><span class="label">Age:</span> {{ $patient->ageLabel($order->ordered_at) }} &nbsp;&nbsp; <span class="label">Sex:</span> {{ $patient->sex?->label() }}</td>
                </tr>
                <tr>
                    <td colspan="2"><span class="label">Referred by:</span> {{ $consultant ?? 'Self' }}</td>
                </tr>
            </table>

            <div class="study">{{ $study->study_name }}</div>

            <div class="section">Findings</div>
            <div class="text">{!! $version->findings !!}</div>

            <div class="section">Impression</div>
            <div class="text impression">{!! $version->impression !!}</div>

            @if ($version->advice)
                <div class="section">Advice</div>
                <div class="text">{!! nl2br(e($version->advice)) !!}</div>
            @endif

            @if ($version->version > 1)
                <div class="amended">Amended report (version {{ $version->version }}, signed {{ $fmt($version->signed_at) }}). It replaces the earlier version.@if ($version->amend_reason) Reason: {{ $version->amend_reason }}@endif</div>
            @endif

            @if ($page['signer'])
                <table class="signers"><tr>
                    <td style="width: 55%"></td>
                    <td>
                        @if ($page['signer']['signature'])<img src="{{ $page['signer']['signature'] }}" alt=""><br>@endif
                        <div class="line">{{ $page['signer']['name'] }}</div>
                        @foreach ($page['signer']['lines'] as $line)<div>{{ $line }}</div>@endforeach
                    </td>
                </tr></table>
            @endif
        </div>
    @endforeach
</body>
</html>
