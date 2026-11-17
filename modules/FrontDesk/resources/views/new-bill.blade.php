@php
    use Illuminate\Support\Str;
    use Modules\FrontDesk\Enums\PaymentMethod;

    $lines = $this->lines();
    $f = $this->figures();
    $locked = $patientId !== null;
    $discountProblem = $this->discountProblem();
    $timezone = app(\App\Core\Tenancy\ClinicContext::class)->require()->timezone;
    $messages = collect($errors->all())->unique()->values();
    $initials = Str::of($name)->squish()->explode(' ')->filter()->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    $chosenCount = count(array_filter($lines, fn ($l) => ! $l['auto']));
@endphp
<x-filament-panels::page>
    <style>
        .bill { --g: #14403A; --a: #17735D; --soft: #EAF3EF; --line: #D5E2DC; --ink: #1B2B27; --mute: #5B6F68; --auto: #FFF7D6;
                font-size: 14px; line-height: 1.4; color: var(--ink); max-width: 1680px; margin: 0 auto; display: grid; gap: 16px; }
        .bill * { box-sizing: border-box; }

        .bill .bar { display: flex; flex-wrap: wrap; gap: 10px 14px; align-items: center; justify-content: space-between; }
        .bill .bar h1 { margin: 0; font-size: 22px; font-weight: 700; color: var(--g); letter-spacing: -.01em; }
        .bill .chips { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .bill .chip { display: inline-flex; gap: 6px; align-items: center; padding: 5px 12px; border-radius: 999px; background: #FFFFFF; border: 1px solid var(--line); color: var(--mute); font-size: 12.5px; }
        .bill .chip b { color: var(--ink); font-weight: 600; }
        .bill kbd { font: 600 11.5px/1 ui-monospace, SFMono-Regular, Menlo, monospace; padding: 3px 6px; border-radius: 5px; background: var(--soft); border: 1px solid var(--line); color: var(--g); }

        .bill .cols { display: grid; grid-template-columns: minmax(0, 1fr) 390px; gap: 20px; align-items: start; }
        .bill .stack { display: grid; grid-template-columns: minmax(0, 1fr); gap: 16px; min-width: 0; }
        .bill .stack > *, .bill .card { min-width: 0; }
        .bill .card { background: #FFFFFF; border: 1px solid var(--line); border-radius: 14px; box-shadow: 0 1px 2px rgba(20, 64, 58, .05), 0 6px 18px rgba(20, 64, 58, .05); }
        .bill .card > header { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 18px; border-bottom: 1px solid var(--line); }
        .bill .card h2 { margin: 0; display: flex; gap: 8px; align-items: center; font-size: 15px; font-weight: 700; color: var(--g); }
        .bill .card h2 svg { width: 19px; height: 19px; color: var(--a); }
        .bill .body { padding: 16px 18px 18px; }

        .bill .fields { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 14px 16px; }
        .bill .c2 { grid-column: span 2; } .bill .c3 { grid-column: span 3; } .bill .c4 { grid-column: span 4; } .bill .c5 { grid-column: span 5; } .bill .c6 { grid-column: span 6; }
        .bill label { display: block; margin: 0 0 5px; font-size: 12px; font-weight: 600; color: var(--mute); letter-spacing: .01em; }
        .bill label i { color: #C0392B; font-style: normal; }
        .bill input, .bill select, .bill textarea { width: 100%; min-width: 0; height: 38px; padding: 0 12px; font-size: 14px; color: var(--ink); background: #FFFFFF; border: 1px solid #C4D4CD; border-radius: 9px; box-shadow: none; appearance: auto; transition: border-color .12s, box-shadow .12s; }
        .bill input::placeholder { color: #93A59E; }
        .bill input:hover, .bill select:hover { border-color: #9FB8AE; }
        .bill input:focus, .bill select:focus { outline: 0; border-color: var(--a); box-shadow: 0 0 0 3px rgba(23, 115, 93, .18); }
        .bill input[readonly], .bill input[disabled], .bill select[disabled] { background: #F1F5F3; color: #3F524C; }
        .bill .bad { border-color: #C0392B !important; background: #FEF3F2 !important; }
        .bill .num { text-align: right; font-variant-numeric: tabular-nums; }
        .bill .split { display: grid; grid-template-columns: minmax(0, 1fr) 76px; gap: 6px; }

        .bill .patient-banner { display: flex; align-items: center; gap: 14px; padding: 12px 14px; margin-bottom: 14px; border-radius: 12px; background: var(--soft); border: 1px solid #BFD8CD; }
        .bill .avatar { width: 44px; height: 44px; border-radius: 50%; background: var(--g); color: #fff; display: grid; place-items: center; font-weight: 700; letter-spacing: .02em; flex: none; }
        .bill .patient-banner .who { flex: 1; min-width: 0; }
        .bill .patient-banner .who b { display: block; font-size: 15px; }
        .bill .patient-banner .who span { color: var(--mute); font-size: 12.5px; }
        .bill .found { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding: 10px 12px; margin-bottom: 14px; border-radius: 12px; background: #F7FBF9; border: 1px dashed #9CC4B5; }
        .bill .found .pick { display: inline-flex; gap: 8px; align-items: center; height: auto; width: auto; padding: 6px 12px; border-radius: 999px; background: #fff; border: 1px solid var(--a); color: var(--g); cursor: pointer; font-size: 13px; }
        .bill .found .pick:hover { background: var(--soft); }

        .bill .entry { display: grid; grid-template-columns: 170px minmax(0, 1fr) 90px; gap: 10px; }
        .bill .btn { height: 38px; padding: 0 20px; border-radius: 9px; border: 1px solid var(--g); background: var(--a); color: #fff; font-weight: 700; font-size: 14px; cursor: pointer; width: auto; transition: background .12s; }
        .bill .btn:hover { background: #125F4C; }
        .bill .btn.alt { background: #fff; color: var(--g); }
        .bill .btn.alt:hover { background: var(--soft); }
        .bill .btn[disabled] { opacity: .6; cursor: wait; }
        .bill .link { color: var(--a); font-weight: 600; text-decoration: underline; cursor: pointer; background: none; border: 0; height: auto; width: auto; padding: 0; font-size: 13px; }

        .bill .tests { margin-top: 14px; border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
        .bill .scroll { max-height: calc(100vh - 470px); min-height: 190px; overflow: auto; }
        .bill table { width: 100%; border-collapse: collapse; }
        .bill th { position: sticky; top: 0; z-index: 1; background: #E4EEE9; color: var(--g); font-size: 12px; font-weight: 700; text-align: left; padding: 9px 12px; border-bottom: 1px solid var(--line); text-transform: uppercase; letter-spacing: .04em; }
        .bill td { padding: 9px 12px; border-bottom: 1px solid #EBF1EE; height: 44px; }
        .bill tbody tr:nth-child(even) td { background: #FAFCFB; }
        .bill tbody tr:hover td { background: #F1F8F5; }
        .bill td.code span { display: inline-block; padding: 2px 8px; border-radius: 6px; background: var(--soft); color: var(--g); font: 600 12px/1.4 ui-monospace, SFMono-Regular, Menlo, monospace; white-space: nowrap; }
        .bill td.dept span { color: var(--mute); font-size: 12.5px; }
        .bill tr.autorow td { color: #66786F; background: #FFFCEB !important; }
        .bill .tag { display: inline-block; margin-left: 6px; padding: 1px 8px; border-radius: 999px; background: #FFF0B8; color: #7A5B00; font-size: 11px; font-weight: 600; font-style: normal; }
        .bill .x { width: 28px; height: 28px; padding: 0; border-radius: 50%; border: 0; background: transparent; color: #B42318; cursor: pointer; font-size: 15px; line-height: 1; }
        .bill .x:hover { background: #FEE4E2; }
        .bill .empty { display: grid; place-items: center; gap: 4px; padding: 34px 12px; text-align: center; color: var(--mute); }
        .bill .empty svg { width: 36px; height: 36px; color: #A9C4B9; }
        .bill .foot { display: flex; justify-content: space-between; align-items: center; padding: 10px 14px; background: #F4F8F6; border-top: 1px solid var(--line); font-weight: 600; }
        .bill .foot .t { font-size: 15px; color: var(--g); font-variant-numeric: tabular-nums; }

        .bill .side { position: sticky; top: 76px; }
        .bill .rows { display: grid; gap: 12px; }
        .bill .two { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .bill .line { display: flex; justify-content: space-between; align-items: center; font-size: 14px; }
        .bill .line b { font-variant-numeric: tabular-nums; }
        .bill .net { padding: 16px 18px; border-radius: 14px; color: #fff; background: linear-gradient(135deg, #14403A 0%, #17735D 100%); box-shadow: 0 8px 20px rgba(20, 64, 58, .25); }
        .bill .net small { display: block; font-size: 12px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; opacity: .85; }
        .bill .net strong { display: block; margin-top: 2px; font-size: 34px; line-height: 1.1; font-weight: 800; font-variant-numeric: tabular-nums; letter-spacing: -.01em; }
        .bill .net .sub { display: flex; justify-content: space-between; margin-top: 6px; font-size: 12.5px; opacity: .9; }
        .bill .pills { display: grid; grid-template-columns: repeat(3, 1fr); gap: 6px; }
        .bill .pill { height: 36px; border-radius: 9px; border: 1px solid #C4D4CD; background: #fff; color: var(--ink); font-weight: 600; font-size: 13px; cursor: pointer; padding: 0; width: auto; }
        .bill .pill:hover { border-color: var(--a); }
        .bill .pill.on { background: var(--a); border-color: var(--a); color: #fff; box-shadow: 0 2px 8px rgba(23, 115, 93, .35); }
        .bill .money { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .bill .badge { padding: 9px 12px; border-radius: 10px; background: #F1F5F3; border: 1px solid #DCE7E2; }
        .bill .badge small { display: block; font-size: 11.5px; font-weight: 600; color: var(--mute); text-transform: uppercase; letter-spacing: .05em; }
        .bill .badge strong { font-size: 18px; font-variant-numeric: tabular-nums; }
        .bill .badge.due { background: #FEF3F2; border-color: #F4B7B0; } .bill .badge.due strong { color: #B42318; }
        .bill .badge.ret { background: #FFF6D9; border-color: #E8CD72; } .bill .badge.ret strong { color: #8A5A00; }
        .bill .alert { padding: 9px 12px; border-radius: 10px; font-size: 12.5px; background: #FFF6D9; border: 1px solid #E8CD72; color: #7A5200; }
        .bill .alert.red { background: #FEF3F2; border-color: #F4B7B0; color: #912018; }
        .bill .actions { display: grid; grid-template-columns: 1fr auto; gap: 8px; }
        .bill .save { height: 48px; font-size: 16px; display: flex; align-items: center; justify-content: center; gap: 10px; }
        .bill .save kbd { background: rgba(255,255,255,.18); border-color: rgba(255,255,255,.35); color: #fff; }

        .bill .strip { display: flex; flex-wrap: wrap; gap: 8px 18px; align-items: center; padding: 12px 18px; border-radius: 14px; background: #E8F6EF; border: 1px solid #9CCFB6; }
        .bill .strip.warn { background: #FFF6D9; border-color: #E8CD72; }
        .bill .strip .ok { display: inline-flex; gap: 8px; align-items: center; font-weight: 700; color: var(--g); }
        .bill .strip .ok svg { width: 20px; height: 20px; color: #1B8F5F; }
        .bill .strip a { display: inline-flex; gap: 6px; align-items: center; padding: 6px 12px; border-radius: 8px; background: #fff; border: 1px solid var(--a); color: var(--g); font-weight: 600; text-decoration: none; }
        .bill .strip a:hover { background: var(--soft); }
        .bill .errs { padding: 12px 18px; border-radius: 14px; border: 1px solid #F0B8B2; background: #FEF3F2; color: #912018; }
        .bill .errs ul { margin: 0; padding-left: 18px; }

        /* A 1366 px monitor leaves about 1000 px beside the menu: stack the patient fields in two columns. */
        @media (max-width: 1560px) {
            .bill .cols { grid-template-columns: minmax(0, 1fr) 350px; gap: 16px; }
            .bill .fields { grid-template-columns: repeat(6, minmax(0, 1fr)); }
            .bill .c2, .bill .c3, .bill .c4 { grid-column: span 3; }
            .bill .c5, .bill .c6 { grid-column: span 6; }
            .bill .entry { grid-template-columns: 130px minmax(0, 1fr) 80px; }
            .bill .scroll { max-height: 330px; }
            .bill .net strong { font-size: 30px; }
            .bill th, .bill td { padding-left: 8px; padding-right: 8px; }
            .bill th:nth-child(1) { width: 40px !important; } .bill th:nth-child(2) { width: 92px !important; }
            .bill th:nth-child(4) { width: 15% !important; } .bill th:nth-child(5) { width: 84px !important; }
            .bill th:nth-child(6) { width: 96px !important; } .bill th:nth-child(7) { width: 36px !important; }
        }
        @media (max-width: 1100px) { .bill .cols { grid-template-columns: 1fr; } .bill .side { position: static; } }
    </style>

    <div class="bill"
         x-data="{ code: '', focus(target) { this.$nextTick(() => this.$refs[target] && this.$refs[target].focus()) } }"
         x-init="focus('mobile')"
         @bill-focus.window="focus($event.detail.target)"
         @keydown.window="
            if (($event.ctrlKey || $event.metaKey) && $event.key.toLowerCase() === 's') { $event.preventDefault(); $wire.save(); }
            else if ($event.key === 'F2') { $event.preventDefault(); $wire.newBill(); }
         ">

        <div class="bar">
            <h1>New bill</h1>
            <div class="chips">
                <span class="chip">Date <b>{{ now($timezone)->format('d M Y') }}</b></span>
                <span class="chip">Receipt no <b>assigned on save</b></span>
                <span class="chip"><kbd>Ctrl</kbd>+<kbd>S</kbd> save</span>
                <span class="chip"><kbd>F2</kbd> new bill</span>
                <span class="chip"><kbd>Enter</kbd> in code box adds a test</span>
            </div>
        </div>

        @if ($last)
            <div class="strip {{ $last['note'] ? 'warn' : '' }}">
                <span class="ok"><x-filament::icon icon="heroicon-o-check-circle" /> Saved {{ $last['no'] }}</span>
                <span>{{ $last['patient'] }}</span>
                <span>Net <b>{{ $last['net'] }}</b></span>
                <span>Paid <b>{{ $last['paid'] }}</b></span>
                <span>Due <b>{{ $last['due'] }}</b></span>
                @if ($last['change'])<b>Return {{ $last['change'] }}</b>@endif
                <a href="{{ route('frontdesk.print.invoice', $last['invoice']) }}" target="_blank"><x-filament::icon icon="heroicon-o-printer" style="width:16px;height:16px" /> Print invoice</a>
                <a href="{{ route('frontdesk.print.invoice', $last['invoice']) }}?copy=office" target="_blank">Office copy</a>
                @if ($last['note'])<span>{{ $last['note'] }}</span>@endif
            </div>
        @endif

        @if ($messages->isNotEmpty())
            <div class="errs"><ul>@foreach ($messages as $message)<li>{{ $message }}</li>@endforeach</ul></div>
        @endif

        <div class="cols">
            <div class="stack">
                <section class="card">
                    <header>
                        <h2><x-filament::icon icon="heroicon-o-user-circle" /> Patient</h2>
                        <div style="display:flex;gap:8px;align-items:center">
                            <label for="pid" style="margin:0">Registered patient?</label>
                            <input id="pid" wire:model="patientCode" wire:keydown.enter.prevent="findPatient" placeholder="Patient ID + Enter" tabindex="8" style="width:190px" @disabled($locked)>
                        </div>
                    </header>
                    <div class="body">
                        @if ($locked)
                            <div class="patient-banner">
                                <div class="avatar">{{ $initials ?: '?' }}</div>
                                <div class="who"><b>{{ $name }}</b><span>{{ $patientCode }} · {{ $age }} · {{ ucfirst($sex) }} · {{ $mobile }}</span></div>
                                <button type="button" class="link" wire:click="clearPatient">Change patient</button>
                            </div>
                        @elseif ($suggestions !== [])
                            <div class="found">
                                <span>Already registered with this number:</span>
                                @foreach ($suggestions as $s)
                                    <button type="button" class="pick" wire:click="usePatient({{ $s['id'] }})"><b>{{ $s['name'] }}</b> {{ $s['code'] }} · {{ $s['age'] }}</button>
                                @endforeach
                                <span style="color:var(--mute);font-size:12.5px">Click one to use it, or carry on to register a new patient.</span>
                            </div>
                        @endif

                        <div class="fields">
                            <div class="c3">
                                <label>Mobile <i>*</i></label>
                                <input wire:model.live.debounce.500ms="mobile" x-ref="mobile" inputmode="tel" tabindex="1" placeholder="01XXX-XXXXXX" @readonly($locked) class="@error('mobile') bad @enderror">
                            </div>
                            <div class="c4">
                                <label>Patient name <i>*</i></label>
                                <input wire:model="name" x-ref="name" tabindex="2" placeholder="Full name" @readonly($locked) class="@error('name') bad @enderror">
                            </div>
                            <div class="c2">
                                <label>Age <i>*</i></label>
                                <div class="split">
                                    <input wire:model="age" inputmode="numeric" tabindex="3" @readonly($locked) class="num @error('age') bad @enderror">
                                    <select wire:model="ageUnit" tabindex="4" @disabled($locked)><option value="Y">Year</option><option value="M">Month</option><option value="D">Day</option></select>
                                </div>
                            </div>
                            <div class="c3">
                                <label>Sex</label>
                                <select wire:model="sex" tabindex="5" @disabled($locked)>
                                    <option value="male">Male</option><option value="female">Female</option><option value="other">Other</option>
                                </select>
                            </div>
                            <div class="c5">
                                <label>Address</label>
                                <input wire:model="address" tabindex="6" placeholder="Village / area, district" @readonly($locked)>
                            </div>
                            <div class="c4">
                                <label>Referred by</label>
                                <select wire:model="doctorId" tabindex="7">
                                    <option value="">Self</option>
                                    @foreach ($this->doctors() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                                </select>
                            </div>
                            <div class="c3">
                                <label>Report delivery</label>
                                <input type="datetime-local" wire:model="deliveryAt" tabindex="9" class="@error('deliveryAt') bad @enderror">
                            </div>
                        </div>
                    </div>
                </section>

                <section class="card">
                    <header>
                        <h2><x-filament::icon icon="heroicon-o-beaker" /> Tests and services</h2>
                        <span class="chip"><b>{{ $chosenCount }}</b> selected</span>
                    </header>
                    <div class="body">
                        <div class="entry">
                            <div wire:ignore>
                                <input x-ref="code" x-model="code" placeholder="Code + Enter" autocomplete="off" tabindex="10"
                                       @keydown.enter.prevent="$wire.addByCode(code); code = ''">
                            </div>
                            <div wire:ignore>
                                <select x-ref="pick" tabindex="11">
                                    <option value="">— or choose a test from the list —</option>
                                    @foreach ($this->catalog() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                                </select>
                            </div>
                            <button type="button" class="btn" tabindex="12"
                                    @click="if ($refs.pick.value) { $wire.addById(parseInt($refs.pick.value)); $refs.pick.value = ''; }">Add</button>
                        </div>

                        <div class="tests">
                            <div class="scroll">
                                <table>
                                    <thead><tr><th style="width:52px">SL</th><th style="width:110px">Code</th><th>Test name</th><th style="width:20%">Department</th><th class="num" style="width:110px">Rate</th><th class="num" style="width:120px">Amount</th><th style="width:44px"></th></tr></thead>
                                    <tbody>
                                        @forelse ($lines as $i => $line)
                                            <tr class="{{ $line['auto'] ? 'autorow' : '' }}">
                                                <td>{{ $i + 1 }}</td>
                                                <td class="code"><span>{{ $line['code'] }}</span></td>
                                                <td>{{ $line['name'] }}@if ($line['auto'])<em class="tag">added automatically</em>@endif</td>
                                                <td class="dept"><span>{{ $line['department'] }}</span></td>
                                                <td class="num">{{ $line['rate']->formatted() }}</td>
                                                <td class="num"><b>{{ $line['rate']->formatted() }}</b></td>
                                                <td>@unless ($line['auto'])<button type="button" class="x" title="Remove" wire:click="removeService({{ $line['id'] }})">✕</button>@endunless</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="7" style="padding:0;height:auto"><div class="empty"><x-filament::icon icon="heroicon-o-beaker" /><b>No tests yet</b><span>Type a test code and press Enter, or choose from the list and press Add.</span></div></td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                            <div class="foot"><span>{{ count($lines) }} item(s)</span><span class="t">Total {{ $f['subtotal']->formatted() }}</span></div>
                        </div>

                        <div style="margin-top:14px">
                            <label>Remarks</label>
                            <input wire:model="notes" maxlength="1000" tabindex="13" placeholder="Printed on the invoice, for example C/O …">
                        </div>
                    </div>
                </section>
            </div>

            <aside class="side">
                <section class="card">
                    <header><h2><x-filament::icon icon="heroicon-o-banknotes" /> Bill summary</h2></header>
                    <div class="body rows">
                        <div class="line"><span>Total bill</span><b>{{ $f['subtotal']->formatted() }}</b></div>

                        <div class="two">
                            <div>
                                <label>Discount %</label>
                                <input wire:model.live.debounce.400ms="discountPercent" inputmode="decimal" tabindex="14" class="num @error('discountPercent') bad @enderror {{ $discountProblem ? 'bad' : '' }}" placeholder="0">
                            </div>
                            <div>
                                <label>Discount Tk</label>
                                <input wire:model.live.debounce.400ms="discountAmount" inputmode="decimal" tabindex="15" class="num @error('discountAmount') bad @enderror {{ $discountProblem ? 'bad' : '' }}" placeholder="0.00">
                            </div>
                        </div>
                        @if ($discountProblem)<div class="alert red">{{ $discountProblem }}</div>@endif
                        @if ($f['discount']->isPositive())
                            <div><label>Reason for discount <i>*</i></label><input wire:model="discountReason" maxlength="255" tabindex="16" placeholder="Why the discount?"></div>
                        @endif

                        <div class="net">
                            <small>Net payable</small>
                            <strong>৳ {{ $f['net']->formatted() }}</strong>
                            @if ($f['discount']->isPositive())<div class="sub"><span>Discount</span><span>− {{ $f['discount']->formatted() }}</span></div>@endif
                        </div>

                        <div>
                            <label>Pay mode</label>
                            <div class="pills">
                                @foreach (PaymentMethod::cases() as $method)
                                    <button type="button" class="pill {{ $payMethod === $method->value ? 'on' : '' }}" wire:click="$set('payMethod', '{{ $method->value }}')">{{ $method->label() }}</button>
                                @endforeach
                            </div>
                        </div>
                        @if ($payMethod !== 'cash')
                            <div><label>Transaction / reference no</label><input wire:model="payReference" maxlength="255" tabindex="18" placeholder="Optional"></div>
                        @endif

                        <div>
                            <div style="display:flex;justify-content:space-between;align-items:baseline"><label>Paid amount</label><button type="button" class="link" wire:click="payExact">Exact amount</button></div>
                            <input wire:model.live.debounce.400ms="paid" inputmode="decimal" tabindex="19" class="num @error('paid') bad @enderror" style="font-size:18px;font-weight:700;height:44px">
                        </div>

                        <div class="money">
                            <div class="badge {{ $f['due']->isPositive() ? 'due' : '' }}"><small>Due</small><strong>{{ $f['due']->formatted() }}</strong></div>
                            <div class="badge {{ $f['change']->isPositive() ? 'ret' : '' }}"><small>Return</small><strong>{{ $f['change']->formatted() }}</strong></div>
                        </div>

                        @if ($this->needsCashSession())
                            <div class="alert">You have no open cash session. Open one under <b>Cash sessions</b>, or the cash payment will be refused.</div>
                        @endif

                        <div class="actions">
                            <button type="button" class="btn save" wire:click="save" wire:loading.attr="disabled" wire:target="save" tabindex="20">Save bill <kbd>Ctrl+S</kbd></button>
                            <button type="button" class="btn alt" wire:click="newBill" tabindex="21" style="height:48px">New <kbd>F2</kbd></button>
                        </div>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</x-filament-panels::page>
