<?php

declare(strict_types=1);

namespace Modules\Messaging;

use App\Core\Modules\Module;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Modules\FrontDesk\Events\OrderPlaced;
use Modules\Laboratory\Events\ResultsReleased;
use Modules\Messaging\Console\DueRemindersCommand;
use Modules\Messaging\Console\RetryCommand;
use Modules\Messaging\Listeners\NotifyPatients;

final class MessagingModule extends Module
{
    public function permissions(): array
    {
        return Permissions::all();
    }

    public function defaultGrants(): array
    {
        return [
            'admin' => array_keys(Permissions::all()),
            'receptionist' => [Permissions::LOG_VIEW],
        ];
    }

    public function exports(): array
    {
        return [
            'msg_messages' => 'Messages sent to patients',
            'msg_templates' => 'Message wording',
            'msg_opt_outs' => 'Numbers that asked for no messages',
        ];
    }

    public function resources(): array
    {
        return class_exists(Filament\Screens::class) ? Filament\Screens::resources() : [];
    }

    public function pages(): array
    {
        return class_exists(Filament\Screens::class) ? Filament\Screens::pages() : [];
    }

    public function boot(): void
    {
        View::addNamespace('messaging', $this->manifest->path.'/resources/views');

        if (is_file($routes = $this->manifest->path.'/routes.php')) {
            // No "auth": the report link is opened by the patient.
            Route::middleware(['web', 'module:messaging'])->group($routes);
        }

        foreach ([OrderPlaced::class, ResultsReleased::class] as $event) {
            Event::listen($event, [NotifyPatients::class, 'handle']);
        }

        Artisan::starting(fn (Artisan $artisan) => $artisan->resolveCommands([DueRemindersCommand::class, RetryCommand::class]));

        app()->afterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command('messaging:due-reminders')->dailyAt('10:30')->timezone(config('dms.clinics.default_timezone'));
            $schedule->command('messaging:retry')->everyTenMinutes();
        });
    }
}
