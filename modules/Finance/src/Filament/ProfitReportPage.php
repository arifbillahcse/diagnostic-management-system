<?php

declare(strict_types=1);

namespace Modules\Finance\Filament;

use App\Core\Modules\GatesModuleAccess;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\Finance\Permissions;
use Modules\Finance\Support\FinanceException;
use Modules\Finance\Support\ProfitReport;
use Throwable;
use UnitEnum;

class ProfitReportPage extends Page
{
    use GatesModuleAccess;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Finance';

    protected static ?int $navigationSort = 70;

    protected static ?string $navigationLabel = 'Profit report';

    protected static ?string $title = 'Profit report';

    protected static ?string $slug = 'finance/profit';

    protected string $view = 'finance::profit';

    public string $from = '';

    public string $to = '';

    public static function moduleKey(): string
    {
        return 'finance';
    }

    public static function permissionKey(): ?string
    {
        return Permissions::REPORTS_VIEW;
    }

    public static function canAccess(): bool
    {
        return static::moduleAllows();
    }

    public function mount(): void
    {
        $today = now('Asia/Dhaka');
        $this->from = $today->copy()->startOfMonth()->toDateString();
        $this->to = $today->toDateString();
    }

    /** @return array{report: ?array<string, mixed>, error: ?string} */
    public function getResultProperty(): array
    {
        if (blank($this->from) || blank($this->to)) {
            return ['report' => null, 'error' => 'Enter both dates.'];
        }

        try {
            return ['report' => app(ProfitReport::class)->for($this->from, $this->to), 'error' => null];
        } catch (FinanceException $e) {
            return ['report' => null, 'error' => $e->getMessage()];
        } catch (Throwable) {
            return ['report' => null, 'error' => 'Enter both dates.'];
        }
    }
}
