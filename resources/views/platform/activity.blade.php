<x-filament-panels::page>
    <x-filament::section>
        @if ($this->rows === [])
            <p class="dms-muted">Nothing yet.</p>
        @else
            <table class="dms-table">
                <thead><tr><th>When</th><th>Who</th><th>Clinic</th><th>What</th><th>Details</th><th>From</th></tr></thead>
                <tbody>
                    @foreach ($this->rows as $row)
                        <tr>
                            <td style="white-space:nowrap">{{ \Carbon\Carbon::parse($row->created_at)->timezone('Asia/Dhaka')->format('d M Y, h:i A') }}</td>
                            <td>{{ $row->who ?? '-' }}</td><td>{{ $row->clinic ?? '-' }}</td><td>{{ $row->action }}</td>
                            <td style="font-size:12px">{{ \Illuminate\Support\Str::limit((string) $row->details, 160) }}</td><td>{{ $row->ip }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
