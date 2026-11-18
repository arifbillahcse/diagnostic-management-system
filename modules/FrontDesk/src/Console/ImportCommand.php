<?php

declare(strict_types=1);

namespace Modules\FrontDesk\Console;

use App\Core\Auth\PermissionChecker;
use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Modules\ModuleState;
use App\Core\Tenancy\Clinic;
use App\Core\Tenancy\ClinicManager;
use App\Core\Tenancy\ReadOnlyMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Modules\FrontDesk\Actions\ImportCatalog;
use Modules\FrontDesk\Permissions;
use Modules\FrontDesk\Support\FrontDeskException;

/** Loads a clinic's test list and prices, or its referral doctors, from a CSV file saved from Excel. */
final class ImportCommand extends Command
{
    protected $signature = 'frontdesk:import
        {clinic : Numeric ID of the clinic (see clinic:list)}
        {what : services or doctors}
        {file : The CSV file (in Excel: File, Save As, CSV UTF-8)}
        {--dry-run : Check and count everything, save nothing}
        {--user= : Email of the staff account to import as (default: the first active owner)}';

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
            $result = $manager->run($clinic, function () use ($what): ?array {
                $email = $this->option('user');
                $user = $email
                    ? User::where('email', $email)->first()
                    : User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('key', Role::OWNER))->orderBy('id')->first();

                if ($problem = $this->whyNot($user)) {
                    $this->error($problem);

                    return null;
                }

                Auth::setUser($user);

                return app(ImportCatalog::class)->{$what}((string) $this->argument('file'), (bool) $this->option('dry-run'));
            });
        } catch (FrontDeskException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            Auth::forgetUser();
        }

        if ($result === null) {
            return self::FAILURE;
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

    /** Says plainly why the import cannot run as this account, instead of a bare "unauthorized". */
    private function whyNot(?User $user): ?string
    {
        if ($user === null) {
            return $this->option('user') ? 'There is no staff account with that email in this clinic.' : 'This clinic has no active owner account. Pass --user=<email> of a staff member who may manage services.';
        }

        if (! $user->is_active) {
            return "The account {$user->email} is deactivated.";
        }

        if (app(ReadOnlyMode::class)->on()) {
            return 'This clinic is read-only because its subscription is unpaid. Renew it first (php artisan clinic:licence).';
        }

        if (! app(ModuleState::class)->isEnabled('frontdesk')) {
            return 'The Front desk module is not enabled for this clinic. Enable it under Settings, Modules, or run php artisan clinic:migrate.';
        }

        if (! app(PermissionChecker::class)->allows($user, Permissions::CATALOG_MANAGE)) {
            return "The account {$user->email} may not manage services. Give its role the permission \"Manage services, packages, prices, and referral doctors\", or pass --user=<email> of an owner.";
        }

        return null;
    }
}
