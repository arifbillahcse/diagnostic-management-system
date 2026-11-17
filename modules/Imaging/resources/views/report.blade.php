@php
    $study = $this->record->loadMissing(['patient', 'order.referralDoctor', 'versions.signer']);
    $tz = app(\App\Core\Tenancy\ClinicContext::class)->timezone();
    $patient = $study->patient;
    $latest = $study->versions->last();
@endphp
<x-filament-panels::page>
    <x-filament::section>
        <dl class="dms-facts">
            <dt>Patient</dt><dd><strong>{{ $patient?->name }}</strong> · {{ $patient?->patient_code }} · {{ $patient?->ageLabel($study->order->ordered_at) }} · {{ $patient?->sex?->label() }}</dd>
            <dt>Order</dt><dd>{{ $study->order->order_no }} · {{ $study->order->ordered_at?->timezone($tz)->format('d M Y, h:i A') }}</dd>
            <dt>Referred by</dt><dd>{{ $study->order->referralDoctor?->name ?? 'Self' }}</dd>
            <dt>Status</dt>
            <dd>
                <x-filament::badge :color="\Modules\Imaging\Enums::color($study->status)">{{ $this->statusLabel() }}</x-filament::badge>
                @if ($study->status === 'amending' && $study->amend_reason) <span class="dms-small">Correcting: {{ $study->amend_reason }}</span>@endif
            </dd>
        </dl>
    </x-filament::section>

    @if ($this->canWrite() || $study->status === 'pending' || $study->status === 'draft' || $study->status === 'amending')
        <x-filament::section :heading="$study->status === 'amending' ? 'Amendment' : 'Report'">
            {{ $this->form }}
            @unless ($this->canWrite())
                <p class="dms-muted" style="margin-top:8px">Only a reporting doctor can write this report.</p>
            @endunless
        </x-filament::section>
    @endif

    @if ($latest && $study->status === 'final')
        <x-filament::section heading="Signed report{{ $latest->version > 1 ? ' (version '.$latest->version.')' : '' }}">
            <div class="dms-report-text">
                <h4>Findings</h4>{!! $latest->findings !!}
                <h4>Impression</h4><div style="font-weight:600">{!! $latest->impression !!}</div>
                @if ($latest->advice)<h4>Advice</h4><p>{!! nl2br(e($latest->advice)) !!}</p>@endif
            </div>
            <p class="dms-small" style="margin-top:8px">Signed by {{ $latest->signer?->name }} on {{ $latest->signed_at->timezone($tz)->format('d M Y, h:i A') }}.</p>
        </x-filament::section>
    @endif

    @if ($study->versions->count() > ($study->status === 'final' ? 1 : 0))
        <x-filament::section heading="Earlier versions" collapsible collapsed>
            @foreach ($study->versions->reverse() as $version)
                @continue($study->status === 'final' && $version->is($latest))
                <div style="border-top:1px solid #e5e7eb;padding-top:8px;margin-top:8px">
                    <p class="dms-small"><strong>Version {{ $version->version }}</strong>, signed by {{ $version->signer?->name }} on {{ $version->signed_at->timezone($tz)->format('d M Y, h:i A') }}@if ($version->amend_reason) · correction: {{ $version->amend_reason }}@endif</p>
                    <div class="dms-report-text">{!! $version->findings !!}<div style="font-weight:600">{!! $version->impression !!}</div></div>
                </div>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
