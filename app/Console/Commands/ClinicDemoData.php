<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleState;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Fills a new, empty clinic with sample data for every module, in one go, so
 * every screen has something to look at. It switches the modules on, then runs
 * each module's own sample command, in an order that suits them. Commands are
 * called by name, so a module that is missing or off is skipped, never needed.
 */
final class ClinicDemoData extends Command
{
    protected $signature = 'clinic:demo-data
        {clinic : Numeric ID of a new, empty clinic (see clinic:list)}
        {--password= : Password for the sample staff accounts; one is made when omitted}
        {--days=10 : How many days of history for the orders}
        {--force : Do not ask for confirmation (and allow it on a production server)}';

    protected $description = 'Fill a new clinic with sample data for every module, so every screen has something to show';

    /** Modules to switch on, dependencies first. */
    private const MODULES = ['frontdesk', 'laboratory', 'reports', 'finance', 'owner', 'inventory', 'imaging', 'messaging', 'portal', 'people', 'multibranch', 'analyzers'];

    /** [what it loads, command, module it needs] */
    private const STEPS = [
        ['Tests, prices, doctors, patients, staff, orders and lab work', 'laboratory:sample-data', 'laboratory'],
        ['A second branch and sample transfers', 'laboratory:sample-branches', 'multibranch'],
        ['Cash session and refunds', 'frontdesk:sample-data', 'frontdesk'],
        ['Stock, supplier and purchase', 'inventory:sample-data', 'inventory'],
        ['Imaging templates and signed reports', 'imaging:sample-data', 'imaging'],
        ['Staff, attendance, advance and salary run', 'people:sample-data', 'people'],
        ['Lab machines and received messages', 'analyzers:sample-data', 'analyzers'],
        ['Expenses, commission, payout and transfer', 'finance:sample-data', 'finance'],
        ['SMS settings and the message log', 'messaging:sample-data', 'messaging'],
        ['Patient portal: a bill that opens a released report', 'portal:sample-data', 'portal'],
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

        if (! $this->option('force') && ! $this->confirm("Fill \"{$clinic->name}\" with sample data for every module? It cannot be removed afterwards, so use a new clinic.")) {
            return self::FAILURE;
        }

        $off = $this->switchOnModules($manager, $clinic);

        if ($off !== []) {
            $this->warn('Not switched on (not in this clinic\'s plan, or not installed): '.implode(', ', $off));
        }

        foreach (self::STEPS as $number => [$what, $command, $module]) {
            $this->line(sprintf('<fg=cyan>%d/%d</> %s', $number + 1, count(self::STEPS), $what));

            if (in_array($module, $off, true) || ! array_key_exists($command, Artisan::all())) {
                $this->line('     skipped: the module is not available for this clinic');

                continue;
            }

            $arguments = ['clinic' => $clinic->id, '--force' => true];

            if ($command === 'laboratory:sample-data') {
                $arguments['--days'] = (int) $this->option('days');

                if ($this->option('password') !== null) {
                    $arguments['--password'] = $this->option('password');
                }
            }

            $output = new BufferedOutput;
            $code = Artisan::call($command, $arguments, $output);

            foreach (array_filter(array_map('rtrim', explode("\n", $output->fetch()))) as $line) {
                $this->line('     '.$line);
            }

            if ($code !== self::SUCCESS) {
                $this->error("Stopped at {$command}. Fix the message above and run the rest of the commands one by one, or use a new clinic and start again.");

                return self::FAILURE;
            }
        }

        $this->tour($clinic);

        return self::SUCCESS;
    }

    /** @return list<string> the modules that could not be switched on */
    private function switchOnModules(ClinicManager $manager, Clinic $clinic): array
    {
        return $manager->run($clinic, function (): array {
            Auth::setUser(User::whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))->firstOrFail());
            $off = [];

            try {
                foreach (self::MODULES as $key) {
                    try {
                        app(ModuleManager::class)->enable($key);
                    } catch (Throwable) {
                        $off[] = $key;
                    }
                }

                // A module whose own requirement failed is not really on.
                return array_values(array_filter(self::MODULES, fn (string $key): bool => ! app(ModuleState::class)->isEnabled($key)));
            } finally {
                Auth::forgetUser();
            }
        });
    }

    private function tour(Clinic $clinic): void
    {
        $this->newLine();
        $this->info("Sample data is ready in \"{$clinic->name}\". Where to look:");
        $this->table(['Module', 'Screen', 'What you will see'], [
            ['Front desk', 'New bill, Orders, Dues', 'Bills at every stage: paid, part-paid, unpaid; discounts; dues'],
            ['Front desk', 'Cash sessions, Refunds', 'An open drawer today, a closed one yesterday Tk 50 short; refunds approved, waiting and rejected'],
            ['Laboratory', 'Samples, Worklist, Reports', 'Samples to collect, in the lab and rejected; results to enter, to verify, released; flagged values'],
            ['Multi-branch', 'Branch switcher, Sample transfers', 'North branch sending samples; one transfer received, one on the way'],
            ['Lab machines', 'Machines, Machine inbox, Test codes', 'Two machines; messages saved, partly saved, unmatched and unreadable'],
            ['Imaging', 'Imaging reports, Report templates', 'Ultrasound and X-ray reports, signed and waiting'],
            ['Finance', 'Accounts, Expenses, Referral commission, Profit report', 'Money in each account, expenses by category, commission owed and paid'],
            ['Inventory', 'Stock, Purchases, Use per test', 'Items, one purchase, rules that take stock off per test; low stock'],
            ['Staff', 'Staff, Daily attendance, Salary runs', 'Four staff, two months of attendance, an advance, a draft salary run'],
            ['Messaging', 'Message log, Message wording', 'Bill, report and due messages; one failed; one number opted out'],
            ['Owner', 'Dashboard, Sales reports', 'Collections chart and reports by day, test, doctor, cashier and branch'],
            ['Patient portal', '/reports on the clinic address', 'The bill and mobile number are printed under the last step above'],
        ]);
        $this->line('Sign in as the clinic owner, or as one of the sample staff accounts printed under step 1.');
        $this->warn('This is made-up data. Never use it for a real clinic, and review the reference ranges before real use.');
    }
}
