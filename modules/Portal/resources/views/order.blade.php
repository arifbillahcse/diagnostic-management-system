@extends('portal::layout')

@section('content')
    <div class="card">
        <h1>{{ $order->patient?->name }}</h1>
        <p class="muted">Bill {{ $order->invoice?->invoice_no }} · {{ $order->ordered_at?->format('d M Y') }}</p>

        <ul class="list">
            @if ($summary['lab_total'] > 0)
                <li><span>Lab tests</span>
                    @if ($summary['lab_ready'] === $summary['lab_total'])<span class="ok">Ready</span>
                    @else<span class="wait">{{ $summary['lab_ready'] }} of {{ $summary['lab_total'] }} ready</span>@endif
                </li>
            @endif
            @foreach ($summary['imaging'] as $study)
                <li><span>{{ $study['name'] }}</span><span class="{{ $study['ready'] ? 'ok' : 'wait' }}">{{ $study['ready'] ? 'Ready' : 'Not ready yet' }}</span></li>
            @endforeach
        </ul>
    </div>

    <div class="card">
        @if ($held)
            <p><strong>Your report is ready.</strong> Please contact the clinic to collect it.</p>
        @elseif ($summary['downloads'] === [])
            <p>Your reports are not ready yet. Please check again later.</p>
            @if ($order->delivery_at)<p class="muted">Expected: {{ $order->delivery_at->timezone(app(\App\Core\Tenancy\ClinicContext::class)->timezone())->format('d M Y, h:i A') }}</p>@endif
        @else
            @foreach ($summary['downloads'] as $kind => $label)
                <a class="btn {{ $kind === 'all' || count($summary['downloads']) === 1 ? '' : 'light' }}" href="{{ route('portal.download', [$order->public_id, $kind]) }}" target="_blank" rel="noopener">Download {{ strtolower($label) }} (PDF)</a>
            @endforeach
        @endif
    </div>

    <form method="post" action="{{ route('portal.leave') }}" style="text-align:center">
        @csrf
        <button class="link" type="submit">Close and look up another bill</button>
    </form>
@endsection
