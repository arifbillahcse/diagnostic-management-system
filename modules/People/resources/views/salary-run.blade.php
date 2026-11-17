@php
    $run = $this->record;
    $tz = app(\App\Core\Tenancy\ClinicContext::class)->timezone();
    $money = fn ($amount) => '৳ '.$amount->formatted();
    $canEdit = $this->canEdit();
    $days = fn ($v) => rtrim(rtrim((string) $v, '0'), '.');
@endphp
<x-filament-panels::page>
    <div class="dms-grid-2">
        <x-filament::section heading="Salary run">
            <dl class="dms-facts">
                <dt>Month</dt><dd>{{ $run->monthLabel() }}</dd>
                <dt>Branch</dt><dd>{{ $run->branch->name }}</dd>
                <dt>Status</dt><dd><x-filament::badge :color="\Modules\People\Filament\SalaryRunResource::statusColor($run->status)">{{ \Modules\People\Models\SalaryRun::STATUSES[$run->status] }}</x-filament::badge></dd>
                @if ($run->approved_at)<dt>Approved</dt><dd>{{ $run->approved_at->timezone($tz)->format('d M Y, h:i A') }}</dd>@endif
                @if ($run->paid_on)<dt>Paid on</dt><dd>{{ $run->paid_on->format('d M Y') }}@if ($run->expense_id) · recorded as an expense @endif</dd>@endif
                @if ($run->cancelled_at)<dt>Cancelled</dt><dd>{{ $run->cancelled_at->timezone($tz)->format('d M Y') }}: {{ $run->cancel_reason }}</dd>@endif
            </dl>
        </x-filament::section>
        <x-filament::section heading="Totals">
            <dl class="dms-facts">
                <dt>Salaries</dt><dd>{{ $money($run->total_gross) }}</dd>
                <dt>Deductions</dt><dd>{{ $money($run->total_deductions) }}</dd>
                <dt>Advances taken back</dt><dd>{{ $money($run->total_advances) }}</dd>
                <dt>Net pay</dt><dd class="dms-strong">{{ $money($run->total_net) }}</dd>
            </dl>
        </x-filament::section>
    </div>

    @if ($this->newAdvances !== [])
        <x-filament::section>
            <p><strong>New advances since this draft was made:</strong> {{ implode(', ', $this->newAdvances) }}. Use "Refresh from attendance" to take them back in this run; typed figures are kept.</p>
        </x-filament::section>
    @endif

    <x-filament::section>
        @if ($this->lines->isEmpty())
            <p class="dms-muted">Nobody worked in this branch in this month.</p>
        @else
            <div class="dms-scroll">
                <table class="dms-table">
                    <thead>
                        <tr>
                            <th>Staff</th>
                            <th class="dms-num" title="Days employed of the month">Days</th>
                            <th class="dms-num">Worked</th>
                            <th class="dms-num">Absent</th>
                            <th class="dms-num">Leave</th>
                            <th class="dms-num">Not marked</th>
                            <th class="dms-num" title="The month's share of the monthly salary">Salary</th>
                            <th>Deduction</th>
                            <th>Advance taken back</th>
                            <th class="dms-num">Net pay</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->lines as $line)
                            <tr wire:key="line-{{ $line->id }}">
                                <td>{{ $line->staff->name }}<div class="dms-small">{{ $line->staff->staff_no }}</div></td>
                                <td class="dms-num">{{ $line->days_employed }}/{{ $line->days_in_month }}</td>
                                <td class="dms-num">{{ $days($line->days_worked) }}</td>
                                <td class="dms-num">{{ $line->days_absent }}</td>
                                <td class="dms-num">{{ $line->days_leave }}</td>
                                <td class="dms-num">{{ $line->days_unmarked }}</td>
                                <td class="dms-num">{{ $money($line->gross) }}</td>
                                @if ($canEdit)
                                    <td style="min-width:200px">
                                        <x-filament::input.wrapper prefix="৳"><x-filament::input type="text" inputmode="decimal" wire:model="edits.{{ $line->id }}.deduction" /></x-filament::input.wrapper>
                                        <x-filament::input.wrapper style="margin-top:4px"><x-filament::input type="text" maxlength="255" placeholder="Reason" wire:model="edits.{{ $line->id }}.note" /></x-filament::input.wrapper>
                                    </td>
                                    <td style="min-width:140px">
                                        <x-filament::input.wrapper prefix="৳"><x-filament::input type="text" inputmode="decimal" wire:model="edits.{{ $line->id }}.recovery" /></x-filament::input.wrapper>
                                        <div class="dms-small" @if ($line->advance_recovery->greaterThan($this->owed[$line->staff_id])) style="color:#dc2626" title="More than is owed now: approving will be refused" @endif>Owed {{ $money($this->owed[$line->staff_id]) }}</div>
                                    </td>
                                @else
                                    <td>{{ $line->deduction->isPositive() ? $money($line->deduction).' · '.$line->deduction_note : '-' }}</td>
                                    <td>{{ $line->advance_recovery->isPositive() ? $money($line->advance_recovery) : '-' }}</td>
                                @endif
                                <td class="dms-num dms-strong">{{ $money($line->net) }}</td>
                                <td>
                                    @if ($canEdit)
                                        <x-filament::button size="sm" wire:click="saveLine({{ $line->id }})">Save</x-filament::button>
                                    @elseif ($run->isSettled())
                                        <x-filament::link :href="route('people.print.payslip', $line->public_id)" target="_blank" size="sm">Payslip</x-filament::link>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($canEdit)
                <p class="dms-small" style="margin-top:8px">Nothing is deducted automatically: type a deduction and its reason if one is due. Advance taken back starts at what is owed; lower it to spread it over months.</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-panels::page>
