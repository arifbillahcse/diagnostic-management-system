@php
    use Modules\Laboratory\Enums\ResultType;

    $fmt = fn ($time) => $time?->timezone($timezone)->format('d M Y, h:i A') ?? '-';
    $firstSample = $items->pluck('sample')->filter()->sortBy('collected_at')->first();
    $pageBottom = $layout['bottom_mm'] + 10; // the @page bottom margin, in mm
    $footerH = $layout['footer_image']['height_mm'] ?? 0;
    $sideShift = fn (array $banner): float => -13 + (210 - $banner['width_mm']) / 2;
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

    @unless ($layout['preprinted'] || $layout['header_image'])
        <table class="letterhead"><tr>
            @if ($clinic['logo'])
                <td class="logo"><img src="{{ $clinic['logo'] }}" alt=""></td>
            @endif
            <td>
                <div class="clinic">{{ $clinic['name'] }}</div>
                @if (! empty($clinic['branch']))<div class="small"><strong>{{ $clinic['branch'] }}</strong></div>@endif
                <div class="small">{{ $clinic['address'] }}</div>
                <div class="small">
                    {{ $clinic['phone'] }}@if ($clinic['phone'] && $clinic['email']) &middot; @endif{{ $clinic['email'] }}
                </div>
                @if ($clinic['header'])<div class="small muted">{!! nl2br(e($clinic['header'])) !!}</div>@endif
            </td>
        </tr></table>
    @endunless

    @include('reports::partials.lab-content')
</body>
</html>
