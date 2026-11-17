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
        .bill { --g: #14403A; --a: #17735D; --soft: #EAF3EF; --line: #D5E2DC; --ink: #1B2B27; --mute: #5B6F68; --auto: #FFF4C7;
                font-size: 13px; line-height: 1.35; color: var(--ink); max-width: 1680px; margin: 0 auto; display: grid; gap: 10px; }
        .bill * { box-sizing: border-box; }

        .bill .bar { display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center; justify-content: space-between; }
        .bill .bar h1 { margin: 0; font-size: 19px; font-weight: 700; color: var(--g); letter-spacing: -.01em; }
        .bill .chips { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .bill .chip { display: inline-flex; gap: 5px; align-items: center; padding: 3px 10px; border-radius: 999px; background: #FFFFFF; border: 1px solid var(--line); color: var(--mute); font-size: 12px; }
        .bill .chip b { color: var(--ink); font-weight: 600; }
        .bill kbd { font: 600 11px/1 ui-monospace, SFMono-Regular, Menlo, monospace; padding: 2px 5px; border-radius: 4px; background: var(--soft); border: 1px solid var(--line); color: var(--g); }

        .bill .card { background: #FFFFFF; border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 1px 2px rgba(20, 64, 58, .05), 0 4px 12px rgba(20, 64, 58, .04); min-width: 0; }
        .bill .card > header { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 7px 14px; border-bottom: 1px solid var(--line); }
        .bill .card h2 { margin: 0; display: flex; gap: 7px; align-items: center; font-size: 14px; font-weight: 700; color: var(--g); }
        .bill .card h2 svg { width: 17px; height: 17px; color: var(--a); }
        .bill .body { padding: 10px 14px 12px; }

        .bill .fields { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 8px 12px; }
        .bill .fields > div { grid-column: span var(--w, 3); min-width: 0; }
        .bill label { display: block; margin: 0 0 3px; font-size: 11.5px; font-weight: 600; color: var(--mute); }
        .bill label i { color: #C0392B; font-style: normal; }
        .bill input, .bill select, .bill textarea { width: 100%; min-width: 0; height: 32px; padding: 0 10px; font-size: 13px; color: var(--ink); background: #FFFFFF; border: 1px solid #C4D4CD; border-radius: 8px; box-shadow: none; appearance: auto; transition: border-color .12s, box-shadow .12s; }
        .bill input::placeholder { color: #93A59E; }
        .bill input:hover, .bill select:hover { border-color: #9FB8AE; }
        .bill input:focus, .bill select:focus { outline: 0; border-color: var(--a); box-shadow: 0 0 0 3px rgba(23, 115, 93, .18); }
        .bill input[readonly], .bill input[disabled], .bill select[disabled] { background: #F1F5F3; color: #3F524C; }
        .bill .bad { border-color: #C0392B !important; background: #FEF3F2 !important; }
        .bill .num { text-align: right; font-variant-numeric: tabular-nums; }
        .bill .split { display: grid; grid-template-columns: minmax(0, 1fr) 74px; gap: 5px; }

        .bill .patient-banner { display: flex; align-items: center; gap: 10px; padding: 6px 10px; margin-bottom: 8px; border-radius: 10px; background: var(--soft); border: 1px solid #BFD8CD; }
        .bill .avatar { width: 30px; height: 30px; border-radius: 50%; background: var(--g); color: #fff; display: grid; place-items: center; font-size: 12px; font-weight: 700; flex: none; }
        .bill .patient-banner .who { flex: 1; min-width: 0; }
        .bill .patient-banner .who b { font-size: 13px; }
        .bill .patient-banner .who span { color: var(--mute); font-size: 12px; margin-left: 8px; }
        .bill .found { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; padding: 6px 10px; margin-bottom: 8px; border-radius: 10px; background: #F7FBF9; border: 1px dashed #9CC4B5; font-size: 12px; }
        .bill .found .pick { display: inline-flex; gap: 6px; align-items: center; height: auto; width: auto; padding: 3px 10px; border-radius: 999px; background: #fff; border: 1px solid var(--a); color: var(--g); cursor: pointer; font-size: 12px; }
        .bill .found .pick:hover { background: var(--soft); }

        .bill .cols { display: grid; grid-template-columns: minmax(0, 1fr) 360px; gap: 10px; align-items: start; }
        .bill .entry { display: grid; grid-template-columns: 150px minmax(0, 1fr) 76px; gap: 8px; }
        .bill .btn { height: 32px; padding: 0 16px; border-radius: 8px; border: 1px solid var(--g); background: var(--a); color: #fff; font-weight: 700; font-size: 13px; cursor: pointer; width: auto; transition: background .12s; }
        .bill .btn:hover { background: #125F4C; }
        .bill .btn.alt { background: #fff; color: var(--g); }
        .bill .btn.alt:hover { background: var(--soft); }
        .bill .btn[disabled] { opacity: .6; cursor: wait; }
        .bill .link { color: var(--a); font-weight: 600; text-decoration: underline; cursor: pointer; background: none; border: 0; height: auto; width: auto; padding: 0; font-size: 12px; }

        .bill .tests { margin-top: 8px; border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
        .bill .scroll { max-height: calc(100vh - 400px); min-height: 160px; overflow: auto; }
        .bill table { width: 100%; border-collapse: collapse; }
        .bill th { position: sticky; top: 0; z-index: 1; background: #E4EEE9; color: var(--g); font-size: 11px; font-weight: 700; text-align: left; padding: 6px 10px; border-bottom: 1px solid var(--line); text-transform: uppercase; letter-spacing: .04em; }
        .bill td { padding: 4px 10px; border-bottom: 1px solid #EBF1EE; height: 34px; }
        .bill tbody tr:nth-child(even) td { background: #FAFCFB; }
        .bill tbody tr:hover td { background: #F1F8F5; }
        .bill td.code span { display: inline-block; padding: 1px 7px; border-radius: 5px; background: var(--soft); color: var(--g); font: 600 11.5px/1.4 ui-monospace, SFMono-Regular, Menlo, monospace; white-space: nowrap; }
        .bill td.dept span { color: var(--mute); font-size: 12px; }
        .bill tr.autorow td { color: #66786F; background: #FFFCEB !important; }
        .bill .tag { display: inline-block; margin-left: 6px; padding: 0 7px; border-radius: 999px; background: #FFF0B8; color: #7A5B00; font-size: 10.5px; font-weight: 600; font-style: normal; white-space: nowrap; }
        .bill .x { width: 24px; height: 24px; padding: 0; border-radius: 50%; border: 0; background: transparent; color: #B42318; cursor: pointer; font-size: 14px; line-height: 1; }
        .bill .x:hover { background: #FEE4E2; }
        .bill .empty { display: grid; place-items: center; gap: 2px; padding: 22px 12px; text-align: center; color: var(--mute); }
        .bill .empty svg { width: 30px; height: 30px; color: #A9C4B9; }
        .bill .foot { display: flex; justify-content: space-between; align-items: center; padding: 6px 12px; background: #F4F8F6; border-top: 1px solid var(--line); font-weight: 600; }
        .bill .foot .t { color: var(--g); font-variant-numeric: tabular-nums; }

        /* Bill summary: the label on the left, the box on the right, as on the earlier counter screen. */
        .bill .sum { display: grid; grid-template-columns: 96px minmax(0, 1fr); gap: 5px 8px; align-items: center; }
        .bill .sum label { margin: 0; font-size: 12px; }
        .bill .sum .auto { background: var(--auto); font-variant-numeric: tabular-nums; text-align: right; }
        .bill .sum .net { height: 38px; font-size: 22px; font-weight: 800; color: #14633F; }
        .bill .sum .due { color: #B42318; font-weight: 700; }
        .bill .sum .ret.on { color: #8A5A00; font-weight: 700; }
        .bill .sum .full { grid-column: 1 / -1; }
        .bill .alert { padding: 6px 10px; border-radius: 8px; font-size: 12px; background: #FFF6D9; border: 1px solid #E8CD72; color: #7A5200; }
        .bill .alert.red { background: #FEF3F2; border-color: #F4B7B0; color: #912018; }
        .bill .actions { display: grid; grid-template-columns: 1fr auto; gap: 6px; margin-top: 8px; }
        .bill .save { height: 38px; font-size: 14px; display: flex; align-items: center; justify-content: center; gap: 8px; }
        .bill .save kbd { background: rgba(255,255,255,.18); border-color: rgba(255,255,255,.35); color: #fff; }

        .bill .strip { display: flex; flex-wrap: wrap; gap: 4px 14px; align-items: center; padding: 7px 14px; border-radius: 12px; background: #E8F6EF; border: 1px solid #9CCFB6; }
        .bill .strip.warn { background: #FFF6D9; border-color: #E8CD72; }
        .bill .strip .ok { display: inline-flex; gap: 6px; align-items: center; font-weight: 700; color: var(--g); }
        .bill .strip .ok svg { width: 18px; height: 18px; color: #1B8F5F; }
        .bill .strip a { display: inline-flex; gap: 5px; align-items: center; padding: 3px 10px; border-radius: 7px; background: #fff; border: 1px solid var(--a); color: var(--g); font-weight: 600; text-decoration: none; }
        .bill .strip a:hover { background: var(--soft); }
        .bill .errs { padding: 8px 14px; border-radius: 12px; border: 1px solid #F0B8B2; background: #FEF3F2; color: #912018; }
        .bill .errs ul { margin: 0; padding-left: 18px; }

        .bill .overlay { position: fixed; inset: 0; z-index: 60; display: grid; place-items: center; padding: 16px; background: rgba(20, 64, 58, .38); }
        .bill .dialog { width: min(460px, 100%); background: #fff; border-radius: 16px; box-shadow: 0 24px 60px rgba(0, 0, 0, .28); overflow: hidden; }
        .bill .dialog .head { display: flex; gap: 12px; align-items: center; padding: 16px 18px; background: var(--soft); border-bottom: 1px solid var(--line); }
        .bill .dialog .head svg { width: 30px; height: 30px; color: #1B8F5F; flex: none; }
        .bill .dialog .head b { display: block; font-size: 15px; color: var(--g); }
        .bill .dialog .head span { color: var(--mute); font-size: 12.5px; }
        .bill .dialog .opts { display: grid; gap: 8px; padding: 16px 18px; }
        .bill .opt { display: flex; align-items: center; justify-content: space-between; gap: 10px; height: 44px; padding: 0 14px; border-radius: 10px; border: 1px solid #C4D4CD; background: #fff; color: var(--ink); font-weight: 600; font-size: 14px; cursor: pointer; width: 100%; text-align: left; }
        .bill .opt:hover { border-color: var(--a); background: var(--soft); }
        .bill .opt.main { background: var(--a); border-color: var(--a); color: #fff; height: 52px; font-size: 15px; }
        .bill .opt.main:hover { background: #125F4C; }
        .bill .opt.main kbd { background: rgba(255,255,255,.2); border-color: rgba(255,255,255,.4); color: #fff; }
        .bill .opt small { font-weight: 500; opacity: .8; margin-left: 6px; }
        .bill .opt .tick { color: #1B8F5F; font-weight: 800; }
        .bill .opt.main .tick { color: #fff; }
        .bill .dialog .foot2 { display: flex; justify-content: space-between; gap: 8px; padding: 12px 18px 16px; border-top: 1px solid var(--line); }

        /* A 1366 px monitor leaves about 1000 px beside the menu. */
        @media (max-width: 1560px) {
            .bill .fields > div { grid-column: span var(--n, 4); }
            .bill .cols { grid-template-columns: minmax(0, 1fr) 330px; }
            .bill .entry { grid-template-columns: 120px minmax(0, 1fr) 70px; }
            .bill .scroll { max-height: calc(100vh - 380px); min-height: 150px; }
            .bill th, .bill td { padding-left: 7px; padding-right: 7px; }
            .bill th:nth-child(1) { width: 36px !important; } .bill th:nth-child(2) { width: 88px !important; }
            .bill th:nth-child(4) { width: 15% !important; } .bill th:nth-child(5) { width: 78px !important; }
            .bill th:nth-child(6) { width: 88px !important; } .bill th:nth-child(7) { width: 32px !important; }
        }
        @media (max-width: 1100px) { .bill .cols { grid-template-columns: 1fr; } }
    </style>

    <div class="bill"
         x-data="{
            code: '',
            printed: {},
            focus(target) { this.$nextTick(() => this.$refs[target] && this.$refs[target].focus()) },
            // Print in a hidden frame, so the counter stays on this screen.
            print(key, url) {
                const frame = document.createElement('iframe');
                frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0';
                frame.src = url + (url.includes('?') ? '&' : '?') + 'autoprint=1';
                document.body.appendChild(frame);
                setTimeout(() => frame.remove(), 120000);
                this.printed[key] = true;
            },
         }"
         x-init="focus('mobile')"
         @bill-focus.window="focus($event.detail.target)"
         @bill-print.window="print('auto', $event.detail.url)"
         @keydown.window="
            if ($wire.printPanel) {
                const k = $event.key.toLowerCase();
                if (k === 'p') { $event.preventDefault(); document.querySelector('[data-print=invoice]')?.click(); }
                else if (k === 'l') { $event.preventDefault(); document.querySelector('[data-print=labels]')?.click(); }
                else if (k === 'escape') { $event.preventDefault(); $wire.closePrint(); }
                else if ($event.key === 'F2') { $event.preventDefault(); $wire.newBill(); }
                return;
            }
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
                <a href="{{ route('frontdesk.print.invoice', $last['invoice']) }}" target="_blank"><x-filament::icon icon="heroicon-o-printer" style="width:15px;height:15px" /> Print invoice</a>
                <a href="{{ route('frontdesk.print.invoice', $last['invoice']) }}?copy=office" target="_blank">Office copy</a>
                @foreach ($last['links'] as $extra)<a href="{{ $extra['url'] }}" target="_blank">{{ $extra['label'] }}</a>@endforeach
                @if ($last['note'])<span>{{ $last['note'] }}</span>@endif
            </div>
        @endif

        @if ($messages->isNotEmpty())
            <div class="errs"><ul>@foreach ($messages as $message)<li>{{ $message }}</li>@endforeach</ul></div>
        @endif

        @if ($printPanel && $last)
            @php $invoiceUrl = route('frontdesk.print.invoice', $last['invoice']); @endphp
            <div class="overlay" @click.self="$wire.closePrint()" x-init="$nextTick(() => $refs.printMain && $refs.printMain.focus())">
                <div class="dialog" role="dialog" aria-label="Print">
                    <div class="head">
                        <x-filament::icon icon="heroicon-o-check-circle" />
                        <div><b>Bill {{ $last['no'] }} saved</b><span>{{ $last['patient'] }} · Net {{ $last['net'] }} · Paid {{ $last['paid'] }} · Due {{ $last['due'] }}@if ($last['change']) · Return {{ $last['change'] }}@endif</span></div>
                    </div>
                    <div class="opts">
                        <button type="button" class="opt main" x-ref="printMain" data-print="invoice" @click="print('invoice', '{{ $invoiceUrl }}?copy=both')">
                            <span>Print invoice <small>patient + office copy</small></span><span><span class="tick" x-show="printed.invoice">✓</span> <kbd>P</kbd></span>
                        </button>
                        @foreach ($last['links'] as $extra)
                            <button type="button" class="opt" data-print="{{ $extra['key'] ?? 'extra' }}" @click="print('{{ $extra['key'] ?? 'extra' }}', '{{ $extra['url'] }}')">
                                <span>Print {{ strtolower($extra['label']) }}</span><span><span class="tick" x-show="printed['{{ $extra['key'] ?? 'extra' }}']">✓</span> <kbd>{{ strtoupper(substr($extra['key'] ?? 'x', 0, 1)) }}</kbd></span>
                            </button>
                        @endforeach
                        <button type="button" class="opt" @click="print('patient', '{{ $invoiceUrl }}')"><span>Patient copy only</span><span class="tick" x-show="printed.patient">✓</span></button>
                        <button type="button" class="opt" @click="print('office', '{{ $invoiceUrl }}?copy=office')"><span>Office copy only</span><span class="tick" x-show="printed.office">✓</span></button>
                    </div>
                    <div class="foot2">
                        <button type="button" class="btn alt" wire:click="closePrint">Close <kbd>Esc</kbd></button>
                        <button type="button" class="btn" wire:click="newBill">New bill <kbd>F2</kbd></button>
                    </div>
                </div>
            </div>
        @endif

        <section class="card">
            <header>
                <h2><x-filament::icon icon="heroicon-o-user-circle" /> Patient</h2>
                <div style="display:flex;gap:8px;align-items:center">
                    <label for="pid" style="margin:0">Registered patient?</label>
                    <input id="pid" wire:model="patientCode" wire:keydown.enter.prevent="findPatient" placeholder="Patient ID + Enter" tabindex="30" style="width:170px" @disabled($locked)>
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
                        <span style="color:var(--mute)">Click one to use it, or carry on to register a new patient.</span>
                    </div>
                @endif

                <div class="fields">
                    <div style="--w:2;--n:3">
                        <label>Mobile <i>*</i></label>
                        <input wire:model.live.debounce.500ms="mobile" x-ref="mobile" inputmode="tel" tabindex="1" placeholder="01XXX-XXXXXX" @readonly($locked) class="@error('mobile') bad @enderror">
                    </div>
                    <div style="--w:3;--n:4">
                        <label>Patient name <i>*</i></label>
                        <input wire:model="name" x-ref="name" tabindex="2" placeholder="Full name" @readonly($locked) class="@error('name') bad @enderror">
                    </div>
                    <div style="--w:2;--n:2">
                        <label>Age <i>*</i></label>
                        <div class="split">
                            <input wire:model="age" inputmode="numeric" tabindex="3" @readonly($locked) class="num @error('age') bad @enderror">
                            <select wire:model="ageUnit" tabindex="4" @disabled($locked)><option value="Y">Year</option><option value="M">Month</option><option value="D">Day</option></select>
                        </div>
                    </div>
                    <div style="--w:2;--n:3">
                        <label>Sex</label>
                        <select wire:model="sex" tabindex="5" @disabled($locked)>
                            <option value="male">Male</option><option value="female">Female</option><option value="other">Other</option>
                        </select>
                    </div>
                    <div style="--w:3;--n:5">
                        <label>Address</label>
                        <input wire:model="address" tabindex="6" placeholder="Village / area, district" @readonly($locked)>
                    </div>

                    <div style="--w:4;--n:4">
                        <label>Referred by</label>
                        <select wire:model="doctorId" tabindex="7">
                            <option value="">Self</option>
                            @foreach ($this->doctors() as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach
                        </select>
                    </div>
                    <div style="--w:3;--n:3">
                        <label>Report delivery</label>
                        <input type="datetime-local" wire:model="deliveryAt" tabindex="8" class="@error('deliveryAt') bad @enderror">
                    </div>
                    <div style="--w:5;--n:12">
                        <label>Remarks</label>
                        <input wire:model="notes" maxlength="1000" tabindex="9" placeholder="Printed on the invoice, for example C/O …">
                    </div>
                </div>
            </div>
        </section>

        <div class="cols">
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
                                <thead><tr><th style="width:46px">SL</th><th style="width:104px">Code</th><th>Test name</th><th style="width:18%">Department</th><th class="num" style="width:96px">Rate</th><th class="num" style="width:104px">Amount</th><th style="width:38px"></th></tr></thead>
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
                </div>
            </section>

            <section class="card">
                <header><h2><x-filament::icon icon="heroicon-o-banknotes" /> Bill summary</h2></header>
                <div class="body">
                    <div class="sum">
                        <label>Total bill</label><input class="auto" readonly tabindex="-1" value="{{ $f['subtotal']->formatted() }}">

                        <label>Discount %</label>
                        <input wire:model.live.debounce.400ms="discountPercent" inputmode="decimal" tabindex="14" class="num @error('discountPercent') bad @enderror {{ $discountProblem ? 'bad' : '' }}" placeholder="0">
                        <label>Discount Tk</label>
                        <input wire:model.live.debounce.400ms="discountAmount" inputmode="decimal" tabindex="15" class="num @error('discountAmount') bad @enderror {{ $discountProblem ? 'bad' : '' }}" placeholder="0.00">

                        @if ($discountProblem)<div class="alert red full">{{ $discountProblem }}</div>@endif
                        @if ($f['discount']->isPositive())
                            <label>Reason <i style="color:#C0392B;font-style:normal">*</i></label>
                            <input wire:model="discountReason" maxlength="255" tabindex="16" placeholder="Why the discount?">
                        @endif

                        <label>Net payable</label><input class="auto net" readonly tabindex="-1" value="{{ $f['net']->formatted() }}">

                        <label>Pay mode</label>
                        <select wire:model="payMethod" tabindex="17">
                            @foreach (PaymentMethod::cases() as $method)<option value="{{ $method->value }}">{{ $method->label() }}</option>@endforeach
                        </select>
                        <label>Trx / Ref No</label><input wire:model="payReference" maxlength="255" tabindex="18">
                        <label>Paid amount<br><button type="button" class="link" wire:click="payExact">exact</button></label>
                        <input wire:model.live.debounce.400ms="paid" inputmode="decimal" tabindex="19" class="num @error('paid') bad @enderror" style="font-weight:700">
                        <label>Due amount</label><input class="auto {{ $f['due']->isPositive() ? 'due' : '' }}" readonly tabindex="-1" value="{{ $f['due']->formatted() }}">
                        <label>Return</label><input class="auto ret {{ $f['change']->isPositive() ? 'on' : '' }}" readonly tabindex="-1" value="{{ $f['change']->formatted() }}">

                        @if ($this->needsCashSession())
                            <div class="alert full">You have no open cash session. Open one under <b>Cash sessions</b>, or the cash payment will be refused.</div>
                        @endif
                    </div>

                    <div class="actions">
                        <button type="button" class="btn save" wire:click="save" wire:loading.attr="disabled" wire:target="save" tabindex="20">Save bill <kbd>Ctrl+S</kbd></button>
                        <button type="button" class="btn alt" wire:click="newBill" tabindex="21" style="height:38px">New <kbd>F2</kbd></button>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
