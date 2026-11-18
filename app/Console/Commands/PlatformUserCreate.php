<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\PlatformUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Adds a person who may use the platform console. They set up two-step sign-in at first sign-in. */
final class PlatformUserCreate extends Command
{
    protected $signature = 'platform:user {email} {name} {--password= : At least 12 characters; asked for when omitted}';

    protected $description = 'Add a platform console user (Softorio staff); two-step sign-in is set up at first sign-in';

    public function handle(): int
    {
        $password = (string) ($this->option('password') ?: $this->secret('Password (at least 12 characters)'));
        $data = ['email' => (string) $this->argument('email'), 'name' => (string) $this->argument('name'), 'password' => $password];

        $validator = Validator::make($data, [
            'email' => ['required', 'email', 'max:255'],
            'name' => ['required', 'max:255'],
            'password' => ['required', Password::min(12)],
        ]);

        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        if (PlatformUser::where('email', $data['email'])->exists()) {
            $this->error('A platform user with that email already exists.');

            return self::FAILURE;
        }

        PlatformUser::create($data + ['is_active' => true]);
        $this->info("Platform user {$data['email']} added. Sign in at https://".(config('dms.platform.domain') ?: '<PLATFORM_DOMAIN not set>').' and set up two-step sign-in.');

        return self::SUCCESS;
    }
}
