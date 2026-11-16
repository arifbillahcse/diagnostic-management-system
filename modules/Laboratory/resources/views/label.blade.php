@php $tz = 'Asia/Dhaka'; @endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Label {{ $sample->accession_no }}</title>
    <style>
        /* Sized for a 50 x 25 mm label roll. One label per page. */
        @page { size: 50mm 25mm; margin: 0; }
        body { margin: 0; padding: 12px; font-family: Arial, Helvetica, sans-serif; color: #000; }
        .label { width: 50mm; height: 25mm; box-sizing: border-box; padding: 1.2mm 1.5mm; overflow: hidden; page-break-after: always; border: 1px dashed #999; margin-bottom: 8px; }
        .name { font-size: 8pt; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .line { font-size: 6.5pt; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        /* Scanners need blank space at both ends of the bars (the quiet zone). */
        .code { padding: 0 4mm; }
        .code svg { width: 100%; height: 9mm; display: block; }
        .number { font-size: 8pt; font-weight: bold; text-align: center; letter-spacing: .12em; }
        .actions { margin-bottom: 12px; }
        button { font-size: 12pt; padding: 8px 20px; cursor: pointer; }
        @media print { .actions { display: none; } body { padding: 0; } .label { border: 0; margin: 0; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Print {{ $copies }} label{{ $copies > 1 ? 's' : '' }}</button></div>

    @for ($i = 0; $i < $copies; $i++)
        <div class="label">
            <div class="name">{{ $sample->patient->name }}</div>
            <div class="line">{{ $sample->patient->patient_code }} &middot; {{ $sample->patient->ageLabel() }} / {{ $sample->patient->sex->label() }}</div>
            <div class="code" role="img" aria-label="Barcode {{ $sample->accession_no }}">{!! $barcode !!}</div>
            <div class="number">{{ $sample->accession_no }}</div>
            <div class="line">{{ $sample->sampleType->name }} &middot; {{ $sample->workItems->pluck('test_name')->implode(', ') }}</div>
        </div>
    @endfor
</body>
</html>
