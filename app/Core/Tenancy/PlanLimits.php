<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Core\Branches\Branch;

/**
 * The limits of the current clinic's plan. A clinic without a plan, or a
 * plan without a limit, has none.
 */
final class PlanLimits
{
    public function __construct(private readonly ClinicContext $clinics) {}

    public function plan(): ?Plan
    {
        return $this->clinics->get()?->plan;
    }

    public function branchLimit(): ?int
    {
        return $this->plan()?->max_branches;
    }

    /** Refuses one more active branch when the plan's limit is reached. */
    public function checkNewBranch(): void
    {
        $limit = $this->branchLimit();

        if ($limit !== null && Branch::where('is_active', true)->count() >= $limit) {
            $plan = $this->plan();

            throw new PlanLimitException("Your plan ({$plan->name}) allows {$plan->branchLimitLabel()}. Upgrade the plan to add another branch, or switch off a branch you no longer use.");
        }
    }
}
