<?php

declare(strict_types=1);

namespace Modules\People;

use App\Core\Modules\Module;
use App\Core\Numbering\NumberGenerator;
use Illuminate\Support\Facades\View;

final class PeopleModule extends Module
{
    public function permissions(): array
    {
        return Permissions::all();
    }

    public function defaultGrants(): array
    {
        return [
            'admin' => array_keys(Permissions::all()),
            'accountant' => [Permissions::STAFF_VIEW, Permissions::SALARY_MANAGE],
        ];
    }

    public function appendOnlyTables(): array
    {
        return ['ppl_salary_changes'];
    }

    public function exports(): array
    {
        return [
            'ppl_staff' => 'Staff',
            'ppl_salary_changes' => 'Salary history',
            'ppl_attendance' => 'Attendance',
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
        View::addNamespace('people', $this->manifest->path.'/resources/views');

        NumberGenerator::registerDefault('staff', 'EMP-{0001}', 'Staff number', 'people');
    }
}
