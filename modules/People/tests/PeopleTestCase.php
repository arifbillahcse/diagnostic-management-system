<?php

declare(strict_types=1);

namespace Modules\People\Tests;

use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Modules\ModuleManager;
use Carbon\CarbonImmutable;
use Modules\People\Actions\ManageStaff;
use Modules\People\Actions\RecordAttendance;
use Modules\People\Models\Staff;
use Tests\TestCase;

/**
 * A clinic with the staff and attendance module on, on a fixed day.
 */
abstract class PeopleTestCase extends TestCase
{
    protected const BASE = 'http://alpha.test';

    protected Branch $branch;

    protected User $ownerUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-17 10:00', 'Asia/Dhaka'));
        $this->useClinic('alpha');
        $this->branch = Branch::firstOrFail();
        $this->ownerUser = $this->owner();
        app(ModuleManager::class)->enable('people');
        $this->actingAs($this->ownerUser);
    }

    protected function staff(string ...$roles): User
    {
        $user = $this->userWithRoles(...$roles);
        $user->forceFill(['default_branch_id' => $this->branch->id])->save();

        return $user;
    }

    protected function person(string $name = 'Karim Mia', string $salary = '15000', string $joined = '2026-01-01', string $designation = 'Lab technician'): Staff
    {
        return app(ManageStaff::class)->add([
            'name' => $name, 'designation' => $designation, 'phone' => '01711000000',
            'branch_id' => $this->branch->id, 'joined_on' => $joined, 'monthly_salary' => $salary,
        ]);
    }

    /** @param array<int, string|array<string, ?string>> $marks */
    protected function mark(string $date, array $marks): int
    {
        return app(RecordAttendance::class)->day($this->branch, $date, array_map(
            fn ($m) => is_string($m) ? ['status' => $m] : $m, $marks,
        ));
    }
}
