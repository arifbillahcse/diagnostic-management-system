@php
    use Modules\FrontDesk\Enums\PaymentMethod;

    $lines = $this->lines();
    $f = $this->figures();
    $locked = $patientId !== null;
    $discountProblem = $this->discountProblem();
    $timezone = app(\App\Core\Tenancy\ClinicContext::class)->require()->timezone;
    $messages = collect($errors->all())->unique()->values();
@endphp
<x-filament-panels::page>
    <style>
        .bill { font-size: 12px; line-height: 1.25; color: #1F2D2A; }
        .bill * { box-sizing: border-box; }
        .bill fieldset { border: 1px solid #C5D3CD; border-radius: 4px; padding: 3px 8px 6px; margin: 0 0 6px; background: #FFFFFF; min-width: 0; }
        .bill legend { font-weight: 700; color: #14403A; font-size: 12px; padding: 0 4px; margin: 0; width: auto; }
        .bill label { color: #4A5F59; font-size: 11.5px; line-height: 1.1; }
        .bill input, .bill select, .bill textarea { height: 24px; padding: 0 5px; font-size: 12px; line-height: 22px; border: 1px solid #B3C3BC; border-radius: 3px; background: #FFFFFF; color: #1F2D2A; width: 100%; min-width: 0; appearance: auto; box-shadow: none; }
        .bill input:focus, .bill select:focus { outline: 2px solid #17735D; outline-offset: -1px; border-color: #17735D; }
        .bill .auto { background: #FFF6CF; font-variant-numeric: tabular-nums; }
        .bill .lock { background: #EEF2F0; color: #2F4540; }
        .bill .bad { border-color: #B42318; background: #FEF3F2; }
        .bill .num { text-align: right; font-variant-numeric: tabular-nums; }
        .bill .top { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 4px 12px; }
        .bill .pair { display: grid; grid-template-columns: 56px minmax(0, 1fr); align-items: center; gap: 4px; }
        .bill .pair.wide2 { grid-column: span 2; }
        .bill td.code { white-space: nowrap; }
        .bill .split { display: grid; grid-template-columns: 1fr 52px; gap: 3px; }
        .bill .main { display: grid; grid-template-columns: minmax(0, 1fr) 300px; gap: 8px; align-items: start; }
        .bill .entry { display: grid; grid-template-columns: 110px minmax(0, 1fr) 54px; gap: 4px; margin-bottom: 4px; }
        .bill .grid-wrap { border: 1px solid #C5D3CD; max-height: calc(100vh - 330px); min-height: 150px; overflow: auto; }
        .bill table { width: 100%; border-collapse: collapse; }
        .bill th { background: #DDE8E3; color: #14403A; font-weight: 700; text-align: left; padding: 3px 6px; border-bottom: 1px solid #B3C3BC; position: sticky; top: 0; }
        .bill td { padding: 2px 6px; border-bottom: 1px solid #E2EBE7; height: 23px; }
        .bill tr.autorow td { color: #5F716B; font-style: italic; }
        .bill .x { color: #B42318; cursor: pointer; border: 0; background: none; padding: 0 4px; height: auto; width: auto; font-size: 14px; line-height: 1; }
        .bill .sum { display: grid; grid-template-columns: 96px minmax(0, 1fr); gap: 3px 6px; align-items: center; }
        .bill .net { font-size: 16px; font-weight: 700; color: #14633F; height: 28px; line-height: 26px; }
        .bill .due { color: #B42318; font-weight: 700; }
        .bill .btn { height: 28px; padding: 0 14px; border-radius: 3px; border: 1px solid #14403A; background: #17735D; color: #FFFFFF; font-weight: 700; cursor: pointer; width: auto; }
        .bill .btn.alt { background: #FFFFFF; color: #14403A; }
        .bill .btn[disabled] { opacity: .6; cursor: wait; }
        .bill .strip { padding: 4px 8px; margin: 0 0 6px; border-radius: 4px; border: 1px solid #9CC9B5; background: #EAF6F0; display: flex; flex-wrap: wrap; gap: 4px 14px; align-items: center; }
        .bill .strip.warn { background: #FFF6CF; border-color: #E4C95C; }
        .bill .errs { padding: 4px 8px; margin: 0 0 6px; border-radius: 4px; border: 1px solid #F0B8B2; background: #FEF3F2; color: #912018; }
        .bill .errs ul { margin: 0; padding-left: 16px; }
        .bill .hint { color: #4A5F59; font-size: 11px; padding: 2px 4px; }
        .bill .chips { display: flex; flex-wrap: wrap; gap: 4px; align-items: center; margin-bottom: 4px; }
        .bill .chip { border: 1px solid #17735D; background: #F0F8F4; color: #14403A; border-radius: 10px; padding: 1px 8px; height: auto; width: auto; line-height: 18px; cursor: pointer; }
        .bill .link { color: #17735D; text-decoration: underline; cursor: pointer; background: none; border: 0; height: auto; width: auto; padding: 0; }
        .bill .note { color: #8A5A00; font-size: 11px; margin-top: 2px; }
        .bill a { color: #17735D; text-decoration: underline; }
        @media (max-width: 1100px) { .bill .top { grid-template-columns: repeat(3, minmax(0, 1fr)); } .bill .main { grid-template-columns: 1fr; } }
    </style>

    <div class="bill"
         x-data="{ code: '', focus(target) { this.$nextTick(() => this.$refs[target] && this.$refs[target].focus()) } }"
         x-init="focus('mobile')"
         @bill-focus.window="focus($event.detail.target)"
         @keydown.window="
            if (($event.ctrlKey || $event.metaKey) && $event.key.toLowerCase() === 's') { $event.preventDefault(); $wire.save(); }
            else if ($event.key === 'F2') { $event.preventDefault(); $wire.newBill(); }
         ">

        @if ($last)
            <div class="strip {{ $last['note'] ? 'warn' : '' }}">
                <strong>Saved {{ $last['no'] }}</strong>
                <span>{{ $last['patient'] }}</span>
                <span>Net {{ $last['net'] }}</span>
                <span>Paid {{ $last['paid'] }}</span>
                <span>Due {{ $last['due'] }}</span>
                @if ($last['change'])<strong>Return {{ $last['change'] }}</strong>@endif
                <a href="{{ route('frontdesk.print.invoice', $last['invoice']) }}" target="_blank">Print invoice</a>
                <a href="{{ route('frontdesk.print.invoice', $last['invoice']) }}?copy=office" target="_blank">Office copy</a>
                @if ($last['note'])<span>{{ $last['note'] }}</span>@endif
            </div>
        @endif

        @if ($messages->isNotEmpty())
            <div class="errs"><ul>@foreach ($messages as $message)<li>{{ $message }}</li>@endforeach</ul></div>
        @endif

        <fieldset>
            <legend>Patient information</legend>

            @if ($suggestions !== [])
                <div class="chips">
                    <span>Already registered:</span>
                    @foreach ($suggestions as $s)
                        <button type="button" class="chip" wire:click="usePatient({{ $s['id'] }})">{{ $s['code'] }} · {{ $s['name'] }} · {{ $s['age'] }}</button>
                    @endforeach
                    <span class="hint">Click one to use it, or carry on to register a new patient.</span>
                </div>
            @endif

            <div class="top">
                <div class="pair"><label>Receipt No</label><input class="auto" value="on save" readonly tabindex="-1"></div>
                <div class="pair"><label>Date</label><input class="auto" value="{{ now($timezone)->format('d/m/Y') }}" readonly tabindex="-1"></div>
                <div class="pair">
                    <label>Patient ID</label>
                    <input wire:model="patientCode" wire:keydown.enter.prevent="findPatient" placeholder="P-… + Enter" tabindex="8" @disabled($locked) class="{{ $locked ? 'lock' : '' }}">
                </div>
                <div class="pair wide2">
                    <label>Name *</label>
                    <div style="display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px;align-items:center">
                        <input wire:model="name" x-ref="name" tabindex="2" @readonly($locked) class="@error('name') bad @enderror {{ $locked ? 'lock' : '' }}">
                        @if ($locked)<button type="button" class="link" wire:click="clearPatient">change</button>@endif
                    </div>
                </div>
                <div class="pair">
                    <label>Age</label>
                    <div class="split">
                        <input wire:model="age" inputmode="numeric" tabindex="3" @readonly($locked) class="num @error('age') bad @enderror {{ $locked ? 'lock' : '' }}">
                        <select wire:model="ageUnit" tabindex="4" @disabled($locked)><option value="Y">Y</option><option value="M">M</option><option value="D">D</option></select>
                    </div>
                </div>
                <div class="pair">
                    <label>Mobile *</label>
                    <input wire:model.live.debounce.500ms="mobile" x-ref="mobile" inputmode="tel" tabindex="1" @readonly($locked) class="@error('mobile') bad @enderror {{ $locked ? 'lock' : '' }}">
                </div>
                <div class="pair">
                    <label>Sex</label>
                    <select wire:model="sex" tabindex="5" @disabled($locked)>
                        <option value="male">Male</option><option value="female">Female</option><option value="other">Other</option>
                    </select>
                </div>
                <div class="pair">
                    <label>Address</label>
                    <input wire:model="address" tabindex="6" title="{{ $address }}" @readonly($locked) class="{{ $locked ? 'lock' : '' }}">
                </div>
                <div class="pair">
                    <label>Ref. Doctor</label>
                    <select wire:model="doctorId" tabindex="7">
                        <option value="">Self</option>
                        @foreach ($this->doctors() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="pair wide2">
                    <label>Delivery</label>
                    <input type="datetime-local" wire:model="deliveryAt" tabindex="9" style="max-width:200px" class="@error('deliveryAt') bad @enderror">
                </div>
            </div>
        </fieldset>

        <div class="main">
            <fieldset>
                <legend>Test entry</legend>

                <div class="entry">
                    <div wire:ignore>
                        <input x-ref="code" x-model="code" placeholder="Code + Enter" autocomplete="off" tabindex="10"
                               @keydown.enter.prevent="$wire.addByCode(code); code = ''">
                    </div>
                    <div wire:ignore>
                        <select x-ref="pick" tabindex="11">
                            <option value="">— select test —</option>
                            @foreach ($this->catalog() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <button type="button" class="btn alt" style="padding:0" tabindex="12"
                            @click="if ($refs.pick.value) { $wire.addById(parseInt($refs.pick.value)); $refs.pick.value = ''; }">Add</button>
                </div>

                <div class="grid-wrap">
                    <table>
                        <thead><tr><th style="width:34px">SL</th><th style="width:92px">Code</th><th>Test name</th><th style="width:20%">Department</th><th class="num" style="width:80px">Rate</th><th class="num" style="width:90px">Amount</th><th style="width:26px"></th></tr></thead>
                        <tbody>
                            @forelse ($lines as $i => $line)
                                <tr class="{{ $line['auto'] ? 'autorow' : '' }}">
                                    <td>{{ $i + 1 }}</td>
                                    <td class="code">{{ $line['code'] }}</td>
                                    <td>{{ $line['name'] }}@if ($line['auto']) <span class="hint">(added automatically)</span>@endif</td>
                                    <td>{{ $line['department'] }}</td>
                                    <td class="num">{{ $line['rate']->formatted() }}</td>
                                    <td class="num">{{ $line['rate']->formatted() }}</td>
                                    <td>@unless ($line['auto'])<button type="button" class="x" title="Remove" wire:click="removeService({{ $line['id'] }})">✕</button>@endunless</td>
                                </tr>
                            @empty
                                <tr><td colspan="7" class="hint" style="padding:10px">No tests yet. Type a code and press Enter, or pick a test and press Add.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="pair" style="grid-template-columns:56px minmax(0,1fr);margin-top:5px">
                    <label>Remarks</label>
                    <input wire:model="notes" maxlength="1000" tabindex="13" placeholder="Printed on the invoice, for example C/O …">
                </div>
            </fieldset>

            <fieldset>
                <legend>Bill summary</legend>
                <div class="sum">
                    <label>Total bill</label><input class="auto num" readonly tabindex="-1" value="{{ $f['subtotal']->formatted() }}">

                    <label>Discount %</label>
                    <input wire:model.live.debounce.400ms="discountPercent" inputmode="decimal" class="num @error('discountPercent') bad @enderror {{ $discountProblem ? 'bad' : '' }}" tabindex="14">
                    <label>Discount Tk</label>
                    <input wire:model.live.debounce.400ms="discountAmount" inputmode="decimal" class="num @error('discountAmount') bad @enderror {{ $discountProblem ? 'bad' : '' }}" tabindex="15">

                    @if ($f['discount']->isPositive())
                        <label>Reason *</label><input wire:model="discountReason" maxlength="255" tabindex="16" placeholder="Why the discount">
                    @endif
                    <label>Net payable</label><input class="auto num net" readonly tabindex="-1" value="{{ $f['net']->formatted() }}">

                    <label>Pay mode</label>
                    <select wire:model="payMethod" tabindex="17">
                        @foreach (PaymentMethod::cases() as $method)<option value="{{ $method->value }}">{{ $method->label() }}</option>@endforeach
                    </select>
                    <label>Trx / Ref No</label><input wire:model="payReference" maxlength="255" tabindex="18">
                    <label>Paid amount</label><input wire:model.live.debounce.400ms="paid" inputmode="decimal" class="num @error('paid') bad @enderror" tabindex="19">
                    <label>Due amount</label><input class="auto num {{ $f['due']->isPositive() ? 'due' : '' }}" readonly tabindex="-1" value="{{ $f['due']->formatted() }}">
                    <label>Return</label><input class="auto num" readonly tabindex="-1" value="{{ $f['change']->formatted() }}">
                </div>

                @if ($discountProblem)<div class="note">{{ $discountProblem }}</div>@endif
                @if ($this->needsCashSession())<div class="note">You have no open cash session. Open one under Cash sessions, or the cash payment will be refused.</div>@endif

                <div style="display:flex;gap:6px;margin-top:8px">
                    <button type="button" class="btn" wire:click="save" wire:loading.attr="disabled" wire:target="save" tabindex="20">Save (Ctrl+S)</button>
                    <button type="button" class="btn alt" wire:click="newBill" tabindex="21">New (F2)</button>
                </div>
            </fieldset>
        </div>

        <div class="hint">Ctrl+S save · F2 new bill · Enter in the code box adds the test · a new patient is registered when the bill is saved</div>
    </div>
</x-filament-panels::page>
