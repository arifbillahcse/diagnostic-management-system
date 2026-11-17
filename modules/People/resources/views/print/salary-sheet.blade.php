@php($money = fn ($m) => $m->formatted())
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Salary sheet {{ $run->monthLabel() }}</title>
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 9.5pt; margin: 0; padding: 12px; }
        h1 { font-size: 14pt; margin: 0; }
        .sub { margin: 2px 0 10px; color: #333; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 4px 5px; text-align: left; vertical-align: top; }
        th { background: #eee; font-size: 8.5pt; }
        .num { text-align: right; white-space: nowrap; }
        tfoot td { font-weight: bold; background: #f4f4f4; }
        .sign { width: 30mm; }
        .foot { display: flex; justify-content: space-between; margin-top: 36px; }
        .foot span { border-top: 1px solid #111; padding-top: 2px; min-width: 55mm; text-align: center; }
        .actions { text-align: center; margin-bottom: 12px; }
        button { font-size: 12pt; padding: 8px 20px; cursor: pointer; }
        @media print { .actions { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Print</button></div>
    <h1>{{ $clinic['name'] }} · Salary sheet {{ $run->monthLabel() }}</h1>
    <div class="sub">{{ $run->branch->name }} · {{ $run->run_no }} · {{ $run->status === 'paid' ? 'Paid '.$run->paid_on->format('d M Y') : 'Approved' }}</div>
    <table>
        <thead><tr>
            <th>#</th><th>Staff no.</th><th>Name</th><th>Designation</th>
            <th class="num">Days</th><th class="num">Worked</th><th class="num">Absent</th><th class="num">Leave</th>
            <th class="num">Monthly salary</th><th class="num">Salary</th><th class="num">Deduction</th><th class="num">Advance</th><th class="num">Net pay</th>
            <th class="sign">Signature</th>
        </tr></thead>
        <tbody>
            @foreach ($lines as $i => $line)
                <tr>
                    <td>{{ $i + 1 }}</td><td>{{ $line->staff->staff_no }}</td><td><strong>{{ $line->staff->name }}</strong></td><td>{{ $line->staff->designation }}</td>
                    <td class="num">{{ $line->days_employed }}/{{ $line->days_in_month }}</td>
                    <td class="num">{{ rtrim(rtrim((string) $line->days_worked, '0'), '.') }}</td>
                    <td class="num">{{ $line->days_absent }}</td><td class="num">{{ $line->days_leave }}</td>
                    <td class="num">{{ $money($line->monthly_salary) }}</td><td class="num">{{ $money($line->gross) }}</td>
                    <td class="num">{{ $line->deduction->isPositive() ? $money($line->deduction) : '-' }}</td>
                    <td class="num">{{ $line->advance_recovery->isPositive() ? $money($line->advance_recovery) : '-' }}</td>
                    <td class="num"><strong>{{ $money($line->net) }}</strong></td>
                    <td class="sign"></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot><tr>
            <td colspan="9">Total ({{ $lines->count() }} staff)</td>
            <td class="num">{{ $money($run->total_gross) }}</td><td class="num">{{ $money($run->total_deductions) }}</td>
            <td class="num">{{ $money($run->total_advances) }}</td><td class="num">{{ $money($run->total_net) }}</td><td></td>
        </tr></tfoot>
    </table>
    <div class="foot"><span>Prepared by</span><span>Checked by</span><span>Approved by</span></div>
</body>
</html>
