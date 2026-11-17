<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $transfer->transfer_no }}</title>
    <style>
        @page { size: A4; margin: 12mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 10pt; margin: 0; padding: 12px; }
        h1 { font-size: 14pt; margin: 0; }
        .row { display: flex; justify-content: space-between; gap: 12px; margin: 8px 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; vertical-align: top; }
        th { background: #eee; }
        .tick { width: 22mm; }
        .sign { display: flex; justify-content: space-between; margin-top: 40px; }
        .sign span { border-top: 1px solid #111; padding-top: 2px; min-width: 55mm; text-align: center; }
        .actions { text-align: center; margin-bottom: 12px; }
        button { font-size: 12pt; padding: 8px 20px; cursor: pointer; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Print</button></div>
    <h1>{{ $clinic['name'] }} · Sample transfer {{ $transfer->transfer_no }}</h1>
    <div class="row">
        <div><strong>From:</strong> {{ $transfer->fromBranch->name }}<br><strong>To:</strong> {{ $transfer->toBranch->name }}</div>
        <div style="text-align:right"><strong>Sent:</strong> {{ $transfer->sent_at->timezone($tz)->format('d M Y, h:i A') }} by {{ $transfer->sender?->name }}<br><strong>Samples:</strong> {{ $transfer->samples->count() }}@if ($transfer->note)<br>{{ $transfer->note }}@endif</div>
    </div>
    <table>
        <thead><tr><th>#</th><th>Sample no.</th><th>Patient</th><th>Sample</th><th>Tests</th><th class="tick">Received</th></tr></thead>
        <tbody>
            @foreach ($transfer->samples->sortBy('accession_no')->values() as $i => $sample)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td><strong>{{ $sample->accession_no }}</strong></td>
                    <td>{{ $sample->patient?->name }}</td>
                    <td>{{ $sample->sampleType?->name }} ({{ $sample->sampleType?->container }})</td>
                    <td>{{ $sample->workItems->pluck('test_name')->implode(', ') }}</td>
                    <td class="tick"></td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <div class="sign"><span>Handed over by</span><span>Courier</span><span>Received at the laboratory by</span></div>
</body>
</html>
