<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Tenancy\ClinicHardening;
use App\Core\Tenancy\ClinicProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

final class ClinicCreate extends Command
{
    protected $signature = 'clinic:create
        {name : Clinic name}
        {domain : Address the clinic will use, for example demo.example.com}
        {--owner-name= : Name of the clinic owner}
        {--owner-email= : Sign-in email of the clinic owner}
        {--owner-password= : Owner password; you are asked for it when omitted}
        {--plan= : Plan key; defaults to the configured default plan}
        {--database= : Use this existing, empty database instead of creating one (cPanel and other shared hosting)}
        {--db-username= : With --database: the MySQL user for that database; defaults to the user in .env}
        {--db-password= : With --db-username: its password; you are asked for it when omitted}';

    protected $description = 'Create a clinic: its database, tables, default roles, and owner account';

    public function handle(ClinicProvisioner $provisioner, ClinicHardening $hardening): int
    {
        if (! $this->option('database') && ($this->option('db-username') || $this->option('db-password'))) {
            $this->error('--db-username and --db-password need --database.');

            return self::FAILURE;
        }

        $databasePassword = $this->option('db-username')
            ? ($this->option('db-password') ?: $this->secret('Password of the MySQL user'))
            : null;

        $input = [
            'name' => $this->argument('name'),
            'domain' => $this->argument('domain'),
            'owner_name' => $this->option('owner-name') ?: $this->ask('Owner name'),
            'owner_email' => $this->option('owner-email') ?: $this->ask('Owner email'),
            'owner_password' => $this->option('owner-password') ?: $this->secret('Owner password'),
        ];

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'domain' => ['required', 'string', 'max:255'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255'],
            'owner_password' => ['required', 'string', Password::defaults()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $clinic = $provisioner->provision(
                $input['name'],
                $input['domain'],
                $input['owner_name'],
                $input['owner_email'],
                $input['owner_password'],
                $this->option('plan'),
                $this->option('database'),
                $this->option('db-username'),
                $databasePassword,
            );
        } catch (Throwable $e) {
            $this->error("The clinic was not created: {$e->getMessage()}");

            if (! $this->option('database') && preg_match('/denied|1044|1045|1227|1410/i', $e->getMessage())) {
                $this->line('This MySQL user may not create databases or users. On cPanel and other shared');
                $this->line('hosting, create an empty database in the hosting panel and pass it with --database.');
                $this->line('See "Shared hosting (cPanel)" in the README.');
            }

            return self::FAILURE;
        }

        $this->info("Clinic \"{$clinic->name}\" created.");
        $this->line("  Address:  {$input['domain']}");
        $this->line("  Database: {$clinic->db_name}");
        $this->line("  Owner:    {$input['owner_email']}");

        if ($hardening->skippedReason() !== null) {
            $this->warn('The clinic works, but this server refused the database triggers that guard');
            $this->warn('append-only tables. The application still never edits those rows.');
            $this->line("  Reason: {$hardening->skippedReason()}");
        }

        return self::SUCCESS;
    }
}
