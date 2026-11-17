<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Payslips {{ $run->monthLabel() }}</title>
    <style>
        @page { size: A5; margin: 10mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 10pt; margin: 0; padding: 12px; }
        .slip { max-width: 128mm; margin: 0 auto 16px; page-break-after: always; }
        .slip:last-child { page-break-after: auto; }
        .head { text-align: center; border-bottom: 1px solid #111; padding-bottom: 6px; margin-bottom: 8px; }
        h1 { font-size: 13pt; margin: 0 0 2px; }
        h2 { font-size: 11pt; margin: 8px 0; text-align: center; letter-spacing: 1px; }
        .sub { font-size: 9pt; }
        table { width: 100%; border-collapse: collapse; margin: 6px 0; }
        td, th { padding: 3px 4px; text-align: left; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        .money td { border-bottom: 1px solid #ddd; }
        .total td { border-top: 1.5px solid #111; font-weight: bold; font-size: 11pt; }
        .days td { border: 1px solid #bbb; text-align: center; font-size: 9pt; }
        .sign { display: flex; justify-content: space-between; margin-top: 36px; font-size: 9pt; }
        .sign span { border-top: 1px solid #111; padding-top: 2px; min-width: 40mm; text-align: center; }
        .actions { text-align: center; margin-bottom: 12px; }
        button { font-size: 12pt; padding: 8px 20px; cursor: pointer; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="actions"><button type="button" onclick="window.print()">Print</button></div>
@foreach ($slips as $slip)
    @php($line = $slip['line'])
    <div class="slip">
        <div class="head">
            @if ($clinic['logo'])<img src="{{ $clinic['logo'] }}" alt="" style="max-height:14mm;max-width:30mm;display:block;margin:0 auto 2px">@endif
            <h1>{{ $clinic['name'] }}</h1>
            <div class="sub">{{ $clinic['address'] }}</div>
        </div>
        <h2>PAYSLIP · {{ strtoupper($run->monthLabel()) }}</h2>
        <table>
            <tr><td>Name</td><td><strong>{{ $line->staff->name }}</strong></td><td>Staff no.</td><td>{{ $line->staff->staff_no }}</td></tr>
            <tr><td>Designation</td><td>{{ $line->staff->designation }}</td><td>Branch</td><td>{{ $run->branch->name }}</td></tr>
            <tr><td>Salary run</td><td>{{ $run->run_no }}</td><td>Status</td><td>{{ $run->status === 'paid' ? 'Paid '.$run->paid_on->format('d M Y') : 'Approved' }}</td></tr>
        </table>
        <table class="days">
            <tr><td>Days in month<br><strong>{{ $line->days_in_month }}</strong></td><td>Employed<br><strong>{{ $line->days_employed }}</strong></td><td>Worked<br><strong>{{ rtrim(rtrim($line->days_worked, '0'), '.') }}</strong></td><td>Absent<br><strong>{{ $line->days_absent }}</strong></td><td>Leave<br><strong>{{ $line->days_leave }}</strong></td><td>Off<br><strong>{{ $line->days_off }}</strong></td></tr>
        </table>
        <table class="money">
            <tr><td>Monthly salary</td><td class="num">৳ {{ $line->monthly_salary->formatted() }}</td></tr>
            <tr><td>Salary for this month{{ $line->days_employed < $line->days_in_month ? " ({$line->days_employed} of {$line->days_in_month} days)" : '' }}</td><td class="num">৳ {{ $line->gross->formatted() }}</td></tr>
            @if ($line->deduction->isPositive())<tr><td>Less deduction: {{ $line->deduction_note }}</td><td class="num">− ৳ {{ $line->deduction->formatted() }}</td></tr>@endif
            @if ($line->advance_recovery->isPositive())<tr><td>Less advance taken back</td><td class="num">− ৳ {{ $line->advance_recovery->formatted() }}</td></tr>@endif
            <tr class="total"><td>Net pay</td><td class="num">৳ {{ $line->net->formatted() }}</td></tr>
        </table>
        <div class="sub">In words: {{ $slip['words'] }}</div>
        <div class="sign"><span>Received by</span><span>Authorised by</span></div>
    </div>
@endforeach
</body>
</html>
