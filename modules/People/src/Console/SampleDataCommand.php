<?php

declare(strict_types=1);

namespace Modules\People\Console;

use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Modules\ModuleState;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Modules\Finance\Models\Account;
use Modules\People\Actions\ManageAdvances;
use Modules\People\Actions\ManageStaff;
use Modules\People\Actions\RecordAttendance;
use Modules\People\Actions\RunPayroll;
use Modules\People\Models\Staff;
use Modules\People\Support\FinanceLink;
use RuntimeException;
use Throwable;

/**
 * Sample staff for trying the staff module: four people, attendance for
 * last month and this month so far, one advance, and a draft salary run
 * for last month.
 */
final class SampleDataCommand extends Command
{
    protected $signature = 'people:sample-data
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {--force : Do not ask for confirmation (and allow it on a production server)}';

    protected $description = 'Load sample staff, attendance, an advance and a draft salary run for trying the staff module';

    /** [name, designation, phone, monthly salary] */
    private const STAFF = [
        ['Kamal Hossain', 'Lab technician', '01711000001', '18000'],
        ['Shirin Akter', 'Receptionist', '01711000002', '14000'],
        ['Mizanur Rahman', 'Sample collector', '01711000003', '12000'],
        ['Rokeya Begum', 'Cleaner', '01711000004', '9000'],
    ];

    public function handle(ClinicManager $manager): int
    {
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error('This server is in production. Add --force only for a clinic that will never hold real data.');

            return self::FAILURE;
        }

        $clinic = Clinic::find((int) $this->argument('clinic'));

        if ($clinic === null) {
            $this->error('There is no clinic with that ID. Run clinic:list to see the IDs.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("Load sample staff into \"{$clinic->name}\"? It cannot be removed afterwards.")) {
            return self::FAILURE;
        }

        try {
            $run = $manager->run($clinic, fn (): string => $this->load($clinic));
        } catch (Throwable $e) {
            $this->error("Sample staff were not loaded: {$e->getMessage()}");

            return self::FAILURE;
        } finally {
            Auth::forgetUser();
        }

        $this->info("Sample staff loaded into \"{$clinic->name}\": 4 staff, attendance for last month and this month, 1 advance, draft salary run {$run}.");

        return self::SUCCESS;
    }

    private function load(Clinic $clinic): string
    {
        if (! app(ModuleState::class)->isEnabled('people')) {
            throw new RuntimeException('The staff module is switched off for this clinic. Turn it on from the Modules page first.');
        }
        if (Staff::query()->exists()) {
            throw new RuntimeException('This clinic already has staff. Sample staff are only for a clinic without any.');
        }

        Auth::setUser(User::whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))->firstOrFail());

        $branch = Branch::firstOrFail();
        $today = CarbonImmutable::now($clinic->timezone)->startOfDay();
        $lastMonth = $today->subMonthNoOverflow()->startOfMonth();
        $people = [];

        foreach (self::STAFF as [$name, $designation, $phone, $salary]) {
            $people[] = app(ManageStaff::class)->add([
                'name' => $name, 'designation' => $designation, 'phone' => $phone, 'branch_id' => $branch->id,
                'joined_on' => $lastMonth->subMonths(6)->toDateString(), 'monthly_salary' => $salary,
            ]);
        }

        // Friday is the weekly off. A few late days, one absence and one leave make the month readable.
        for ($day = $lastMonth; $day->lessThan($today); $day = $day->addDay()) {
            $marks = [];

            foreach ($people as $i => $person) {
                $status = match (true) {
                    $day->isFriday() => 'off',
                    $i === 1 && $day->day % 9 === 0 => 'late',
                    $i === 2 && $day->day === 12 => 'absent',
                    $i === 3 && $day->day === 20 => 'leave',
                    default => 'present',
                };
                $marks[$person->id] = ['status' => $status] + (in_array($status, ['present', 'late'], true)
                    ? ['in_time' => $status === 'late' ? '09:40' : '09:00', 'out_time' => '17:00'] : []);
            }

            app(RecordAttendance::class)->day($branch, $day->toDateString(), $marks);
        }

        $finance = app(FinanceLink::class)->on();
        $account = $finance ? Account::where('is_active', true)->orderBy('id')->value('id') : null;

        if (! $finance || $account !== null) {
            app(ManageAdvances::class)->give($people[2], '3000', $lastMonth->addDays(9)->toDateString(), 'Family need', $account);
        }

        return app(RunPayroll::class)->start($branch, $lastMonth->format('Y-m'))->run_no;
    }
}
