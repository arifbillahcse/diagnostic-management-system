@php
    $clinic = $this->record->loadMissing(['plan', 'domains']);
    $licence = app(\App\Core\Tenancy\Licence::class);
    $usage = $this->usage;
    $end = $licence->endsOn($clinic);
@endphp
<x-filament-panels::page>
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px">
        <x-filament::section heading="Subscription">
            <dl class="dms-facts">
                <dt>Status</dt><dd><x-filament::badge :color="\App\Platform\Filament\Resources\Clinics\ClinicResource::statusColor($clinic->status)">{{ $clinic->status->label() }}</x-filament::badge></dd>
                <dt>Plan</dt><dd>{{ $clinic->plan?->name ?? '-' }} @if ($clinic->plan)· ৳ {{ $clinic->plan->price_monthly->formatted() }} a month · {{ $clinic->plan->branchLimitLabel() }}@endif</dd>
                <dt>Trial ends</dt><dd>{{ $clinic->trial_ends_at?->timezone($clinic->timezone ?: 'Asia/Dhaka')->format('d M Y') ?? '-' }}</dd>
                <dt>Paid until</dt><dd>{{ $clinic->paid_until?->format('d M Y') ?? 'Not paid yet' }}</dd>
                <dt>Covered until</dt><dd>{{ $end?->format('d M Y') ?? 'No end' }}@if ($end) · read-only from {{ $licence->graceEndsOn($clinic)->addDay()->format('d M Y') }}@endif</dd>
            </dl>
        </x-filament::section>
        <x-filament::section heading="Clinic">
            <dl class="dms-facts">
                <dt>Address</dt><dd>{{ $clinic->domains->pluck('domain')->implode(', ') }}</dd>
                <dt>Database</dt><dd>{{ $clinic->db_name }}</dd>
                <dt>Time zone</dt><dd>{{ $clinic->timezone }}</dd>
                <dt>Since</dt><dd>{{ $clinic->created_at?->format('d M Y') }}</dd>
            </dl>
        </x-filament::section>
        <x-filament::section heading="Usage">
            @if ($usage['error'])
                <p class="dms-muted">{{ $usage['error'] }}</p>
            @else
                <dl class="dms-facts">
                    <dt>Bills this month</dt><dd>{{ $usage['bills_this_month'] ?? '-' }}</dd>
                    <dt>Active users</dt><dd>{{ $usage['users'] }}</dd>
                    <dt>Active branches</dt><dd>{{ $usage['branches'] }}@if ($clinic->plan?->max_branches && $usage['branches'] > $clinic->plan->max_branches) <x-filament::badge color="warning">over the plan's limit</x-filament::badge>@endif</dd>
                    <dt>Last sign-in</dt><dd>{{ $usage['last_sign_in'] ? \Carbon\Carbon::parse($usage['last_sign_in'])->timezone($clinic->timezone ?: 'Asia/Dhaka')->format('d M Y, h:i A') : 'Never' }}</dd>
                    <dt>Modules on</dt><dd>{{ implode(', ', $usage['modules']) ?: '-' }}</dd>
                </dl>
            @endif
        </x-filament::section>
    </div>

    <x-filament::section heading="History">
        @if ($this->history === [])
            <p class="dms-muted">Nothing yet.</p>
        @else
            <table class="dms-table">
                <thead><tr><th>When</th><th>What</th><th>Details</th><th>By</th></tr></thead>
                <tbody>
                    @foreach ($this->history as $row)
                        <tr><td style="white-space:nowrap">{{ \Carbon\Carbon::parse($row->at)->timezone('Asia/Dhaka')->format('d M Y, h:i A') }}</td><td>{{ $row->what }}</td><td style="font-size:12px">{{ \Illuminate\Support\Str::limit((string) $row->detail, 160) }}</td><td>{{ $row->who }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-filament::section>
</x-filament-panels::page>
