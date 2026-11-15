<?php

declare(strict_types=1);

namespace App\Console\Commands;

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
        {--plan= : Plan key; defaults to the configured default plan}';

    protected $description = 'Create a clinic: its database, tables, default roles, and owner account';

    public function handle(ClinicProvisioner $provisioner): int
    {
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
            );
        } catch (Throwable $e) {
            $this->error("The clinic was not created: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Clinic \"{$clinic->name}\" created.");
        $this->line("  Address:  {$input['domain']}");
        $this->line("  Database: {$clinic->db_name}");
        $this->line("  Owner:    {$input['owner_email']}");

        return self::SUCCESS;
    }
}
