<?php

declare(strict_types=1);

namespace Modules\Owner;

use App\Core\Modules\Module;
use Illuminate\Support\Facades\View;

final class OwnerModule extends Module
{
    public function permissions(): array
    {
        return Permissions::all();
    }

    public function defaultGrants(): array
    {
        return [
            'admin' => array_keys(Permissions::all()),
            'accountant' => [Permissions::REPORTS_VIEW],
        ];
    }

    public function pages(): array
    {
        return class_exists(Filament\SalesReportsPage::class) ? [Filament\SalesReportsPage::class] : [];
    }

    public function widgets(): array
    {
        return class_exists(Filament\MonthWidget::class) ? [Filament\MonthWidget::class, Filament\CollectionsChart::class] : [];
    }

    public function boot(): void
    {
        View::addNamespace('owner', $this->manifest->path.'/resources/views');
    }
}
