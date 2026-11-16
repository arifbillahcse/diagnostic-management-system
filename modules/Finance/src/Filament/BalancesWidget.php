<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use App\Core\Modules\GatesModuleAccess;
use App\Core\Money\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Modules\Finance\Models\Account;
use Modules\Finance\Permissions;
use Modules\Finance\Support\Ledger;

/** What each account holds now. */
class BalancesWidget extends StatsOverviewWidget
{
    use GatesModuleAccess;

    protected static ?int $sort = -5;

    protected ?string $heading = 'Money held';

    public static function moduleKey(): string
    {
        return 'finance';
    }

    public static function permissionKey(): ?string
    {
        return Permissions::ACCOUNTS_VIEW;
    }

    public static function canView(): bool
    {
        return static::moduleAllows();
    }

    protected function getStats(): array
    {
        $balances = app(Ledger::class)->balances();
        $accounts = Account::where('is_active', true)->orderBy('id')->get();
        $total = Money::sum(...array_values($balances));

        $stats = [Stat::make('All accounts', '৳ '.$total->formatted())];

        foreach ($accounts->take(3) as $account) {
            $stats[] = Stat::make($account->name, '৳ '.($balances[$account->id] ?? Money::zero())->formatted());
        }

        return $stats;
    }
}
