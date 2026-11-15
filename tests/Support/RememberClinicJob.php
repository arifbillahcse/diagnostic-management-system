<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Auth\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/** Test job: records which clinic's data it could see when it ran. */
final class RememberClinicJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /** @var list<string> */
    public static array $seen = [];

    public function handle(): void
    {
        self::$seen[] = User::orderBy('id')->value('email');
    }
}
