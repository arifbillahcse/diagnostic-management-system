<?php

declare(strict_types=1);

namespace Modules\Finance;

use App\Core\Modules\Module;
use App\Core\Modules\ModuleEnabled;
use App\Core\Numbering\NumberGenerator;
use Illuminate\Console\Application as Artisan;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Modules\Finance\Console\SyncCommand;
use Modules\Finance\Listeners\RecordFrontDeskMoney;
use Modules\FrontDesk\Events\OrderCancelled;
use Modules\FrontDesk\Events\OrderItemAdded;
use Modules\FrontDesk\Events\OrderItemCancelled;
use Modules\FrontDesk\Events\PaymentReceived;
use Modules\FrontDesk\Events\RefundIssued;

final class FinanceModule extends Module
{
    public function permissions(): array
    {
        return Permissions::all();
    }

    public function defaultGrants(): array
    {
        return [
            'admin' => array_keys(Permissions::all()),
            'accountant' => array_keys(Permissions::all()),
        ];
    }

    public function appendOnlyTables(): array
    {
        return ['fin_account_entries', 'fin_commission_entries'];
    }

    public function exports(): array
    {
        return [
            'fin_accounts' => 'Accounts',
            'fin_account_entries' => 'Account entries',
            'fin_transfers' => 'Transfers',
            'fin_expense_categories' => 'Expense categories',
            'fin_expenses' => 'Expenses',
            'fin_commission_rules' => 'Commission rates',
            'fin_commission_entries' => 'Commission earned',
            'fin_commission_payouts' => 'Commission payouts',
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

    public function widgets(): array
    {
        return class_exists(Filament\Screens::class) ? Filament\Screens::widgets() : [];
    }

    public function boot(): void
    {
        View::addNamespace('finance', $this->manifest->path.'/resources/views');

        NumberGenerator::registerDefault('expense', 'EXP-{yymm}-{00001}', 'Expense number', 'finance');
        NumberGenerator::registerDefault('payout', 'PAY-{yymm}-{00001}', 'Commission payout number', 'finance');

        foreach ([PaymentReceived::class, RefundIssued::class, OrderItemCancelled::class, OrderItemAdded::class, OrderCancelled::class] as $event) {
            Event::listen($event, [RecordFrontDeskMoney::class, 'handle']);
        }

        // Switched on in a clinic that already has payments: bring the
        // accounts and commission up to date at once.
        Event::listen(ModuleEnabled::class, function (ModuleEnabled $event): void {
            if ($event->key === 'finance') {
                SyncCommand::catchUp();
            }
        });

        Artisan::starting(fn (Artisan $artisan) => $artisan->resolveCommands([SyncCommand::class, Console\SampleDataCommand::class]));

        app()->afterResolving(Schedule::class, function (Schedule $schedule): void {
            // A safety net: anything an interrupted request failed to post.
            $schedule->command('finance:sync')->dailyAt('00:40')->timezone(config('dms.clinics.default_timezone'));
        });
    }
}
