@if ($changes->isEmpty())
    <p class="dms-muted">No salary recorded.</p>
@else
    <table class="dms-table">
        <thead><tr><th>From</th><th class="dms-num">Monthly salary</th><th>Reason</th></tr></thead>
        <tbody>
            @foreach ($changes as $change)
                <tr>
                    <td>{{ $change->effective_from->format('d M Y') }}</td>
                    <td class="dms-num">৳ {{ $change->monthly_salary->formatted() }}</td>
                    <td>{{ $change->reason }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
