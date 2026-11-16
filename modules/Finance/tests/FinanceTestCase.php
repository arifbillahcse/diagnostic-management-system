<?php

declare(strict_types=1);

namespace Modules\Finance\Tests;

use App\Core\Modules\ModuleManager;
use Modules\Finance\Actions\ManageCommission;
use Modules\Finance\Models\Account;
use Modules\Finance\Models\ExpenseCategory;
use Modules\Finance\Support\Ledger;
use Modules\FrontDesk\Models\Order;
use Modules\FrontDesk\Models\ReferralDoctor;
use Modules\FrontDesk\Tests\FrontDeskTestCase;

/**
 * Front desk set-up with the finance module switched on.
 */
abstract class FinanceTestCase extends FrontDeskTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app(ModuleManager::class)->enable('finance');
        $this->actingAs($this->ownerUser);
    }

    protected function balance(string $accountName): string
    {
        return app(Ledger::class)->balance(Account::where('name', $accountName)->firstOrFail())->toDecimal();
    }

    protected function category(string $name = 'Reagents'): ExpenseCategory
    {
        return ExpenseCategory::firstOrCreate(['name' => $name]);
    }

    protected function doctor(string $name = 'Dr. Karim', ?string $percent = '10'): ReferralDoctor
    {
        $doctor = ReferralDoctor::create(['name' => $name]);

        if ($percent !== null) {
            app(ManageCommission::class)->setRates($doctor, ['kind' => 'percent', 'value' => $percent]);
        }

        return $doctor;
    }

    /** @param array<int, mixed> $prices */
    protected function referred(ReferralDoctor $doctor, array $prices, array $options = []): Order
    {
        return $this->order($prices, ['referral_doctor_id' => $doctor->id] + $options);
    }
}
