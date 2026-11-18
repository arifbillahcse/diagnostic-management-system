{{-- The subscription notice at the top of every page. --}}
@php
    $clinic = app(\App\Core\Tenancy\ClinicContext::class)->get();
    $licence = app(\App\Core\Tenancy\Licence::class);
    $status = $clinic?->status;
    $end = $clinic ? $licence->endsOn($clinic) : null;
    $days = $clinic ? $licence->daysLeft($clinic) : null;
    $isOwner = \Illuminate\Support\Facades\Gate::allows(\App\Core\Auth\CorePermissions::SETTINGS_MANAGE);
    $what = $clinic?->paid_until === null ? 'free trial' : 'subscription';

    [$tone, $text] = match (true) {
        $status === \App\Core\Tenancy\ClinicStatus::ReadOnly => ['danger', "Read-only: the {$what} ended on ".$end?->format('d M Y').'. You can view and print reports and bills, but nothing new can be entered until the subscription is renewed.'],
        $status === \App\Core\Tenancy\ClinicStatus::Overdue => ['danger', "Payment overdue: the {$what} ended on ".$end?->format('d M Y').'. Everything works until '.$licence->graceEndsOn($clinic)->format('d M Y').', then the system becomes read-only.'],
        $isOwner && $days !== null && $days >= 0 && $days <= (int) config('dms.clinics.remind_days', 7) => ['warning', "Your {$what} ends on ".$end->format('d M Y')." ({$days} day(s) left). Contact your provider to renew."],
        $isOwner && $status === \App\Core\Tenancy\ClinicStatus::Trial && $end !== null => ['info', 'Free trial until '.$end->format('d M Y').'.'],
        default => [null, null],
    };
    $colors = ['danger' => '#fdecec;color:#8a1c1c;border-color:#f5c2c2', 'warning' => '#fff7e6;color:#7a4a00;border-color:#f3d9a4', 'info' => '#eef6f2;color:#1f5a44;border-color:#cfe5da'];
@endphp
@if ($text)
    <div class="dms-licence" role="status" style="margin:0 0 12px;padding:10px 14px;border:1px solid;border-radius:8px;font-size:14px;background:{{ $colors[$tone] }}">{{ $text }}</div>
@endif
