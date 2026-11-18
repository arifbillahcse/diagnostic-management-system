<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Console;

use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Modules\FrontDesk\Actions\ImportCatalog;
use Modules\FrontDesk\Support\FrontDeskException;

/** Loads a clinic's test list and prices, or its referral doctors, from a CSV file saved from Excel. */
final class ImportCommand extends Command
{
    protected $signature = 'frontdesk:import
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {what : services or doctors}
        {file : The CSV file (in Excel: File, Save As, CSV UTF-8)}
        {--dry-run : Check and count everything, save nothing}';

    protected $description = 'Load a clinic\'s test list and prices, or its referral doctors, from a CSV file; all rows or none';

    public function handle(ClinicManager $manager): int
    {
        $clinic = Clinic::find((int) $this->argument('clinic'));
        $what = (string) $this->argument('what');

        if ($clinic === null || ! in_array($what, ['services', 'doctors'], true)) {
            $this->error($clinic === null ? 'There is no clinic with that ID.' : 'Say what to load: services or doctors.');

            return self::FAILURE;
        }

        try {
            $result = $manager->run($clinic, function () use ($what): array {
                Auth::setUser(User::whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))->firstOrFail());

                return app(ImportCatalog::class)->{$what}((string) $this->argument('file'), (bool) $this->option('dry-run'));
            });
        } catch (FrontDeskException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            Auth::forgetUser();
        }

        if ($result['errors'] !== []) {
            $this->error('Nothing was saved. Fix these rows and try again:');
            foreach (array_slice($result['errors'], 0, 50) as $error) {
                $this->line("  {$error}");
            }

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? 'Trial run, nothing saved: ' : 'Saved: ')."{$result['created']} new, {$result['updated']} updated, {$result['unchanged']} unchanged.");

        return self::SUCCESS;
    }
}
