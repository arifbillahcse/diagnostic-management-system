<?php

declare(strict_types=1);

namespace Modules\Owner\Filament;

use App\Core\Modules\GatesModuleAccess;
use App\Core\Money\Money;
use BackedEnum;
use DomainException;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Modules\FrontDesk\Enums\PaymentMethod;
use Modules\Owner\Permissions;
use Modules\Owner\Support\SalesReports;
use Throwable;
use UnitEnum;

class SalesReportsPage extends Page
{
    use GatesModuleAccess;

    public const REPORTS = [
        'day' => 'Day by day',
        'test' => 'By test',
        'doctor' => 'By referral doctor',
        'cashier' => 'By cashier',
    ];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'Sales reports';

    protected static ?string $navigationLabel = 'Sales reports';

    protected static ?string $title = 'Sales reports';

    protected static ?string $slug = 'sales-reports';

    protected string $view = 'owner::sales-reports';

    public string $report = 'day';

    public string $from = '';

    public string $to = '';

    public static function moduleKey(): string
    {
        return 'owner';
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
        [$this->from, $this->to] = app(SalesReports::class)->thisMonth();
    }

    /**
     * The chosen report as plain headings and rows, ready to draw. Columns
     * after the first $text ones are numbers and are right-aligned.
     *
     * @return array{headings: list<string>, rows: list<list<string>>, totals: ?list<string>, text: int, error: ?string}
     */
    public function getTableProperty(): array
    {
        $reports = app(SalesReports::class);
        $tk = fn (Money $amount): string => $amount->formatted();
        $sum = fn (array $rows, string $key): Money => Money::sum(...array_column($rows, $key));

        if (blank($this->from) || blank($this->to)) {
            return ['headings' => [], 'rows' => [], 'totals' => null, 'text' => 1, 'error' => 'Enter both dates.'];
        }

        try {
            if (! array_key_exists($this->report, self::REPORTS)) {
                throw new DomainException('Choose a report.');
            }

            return ['error' => null] + match ($this->report) {
                'day' => [
                    'headings' => ['Date', 'Orders', 'Patients', 'Billed', 'Collected', 'Refunded'],
                    'rows' => array_map(fn (array $r): array => [$r['date']->format('D, d M Y'), (string) $r['orders'], (string) $r['patients'], $tk($r['billed']), $tk($r['collected']), $tk($r['refunded'])], $rows = $reports->byDay($this->from, $this->to)),
                    'totals' => ['Total', (string) array_sum(array_column($rows, 'orders')), (string) array_sum(array_column($rows, 'patients')), $tk($sum($rows, 'billed')), $tk($sum($rows, 'collected')), $tk($sum($rows, 'refunded'))],
                    'text' => 1,
                ],
                'test' => [
                    'headings' => ['Test', 'Sold', 'Price total', 'Discount', 'Net'],
                    'rows' => array_map(fn (array $r): array => [$r['name'], (string) $r['count'], $tk($r['gross']), $tk($r['discount']), $tk($r['net'])], $rows = $reports->byTest($this->from, $this->to)),
                    'totals' => ['Total', (string) array_sum(array_column($rows, 'count')), $tk($sum($rows, 'gross')), $tk($sum($rows, 'discount')), $tk($sum($rows, 'net'))],
                    'text' => 1,
                ],
                'doctor' => [
                    'headings' => ['Referred by', 'Orders', 'Net billed'],
                    'rows' => array_map(fn (array $r): array => [$r['name'], (string) $r['orders'], $tk($r['net'])], $rows = $reports->byDoctor($this->from, $this->to)),
                    'totals' => ['Total', (string) array_sum(array_column($rows, 'orders')), $tk($sum($rows, 'net'))],
                    'text' => 1,
                ],
                'cashier' => [
                    'headings' => ['Received by', 'Method', 'Payments', 'Amount'],
                    'rows' => array_map(fn (array $r): array => [$r['name'], PaymentMethod::from($r['method'])->label(), (string) $r['payments'], $tk($r['amount'])], $rows = $reports->byCashier($this->from, $this->to)),
                    'totals' => ['Total', '', (string) array_sum(array_column($rows, 'payments')), $tk($sum($rows, 'amount'))],
                    'text' => 2,
                ],
            };
        } catch (DomainException $e) {
            return ['headings' => [], 'rows' => [], 'totals' => null, 'text' => 1, 'error' => $e->getMessage()];
        } catch (Throwable) {
            return ['headings' => [], 'rows' => [], 'totals' => null, 'text' => 1, 'error' => 'Enter both dates.'];
        }
    }
}
