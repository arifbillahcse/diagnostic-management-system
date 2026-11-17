<?php

declare(strict_types=1);

namespace App\Core\Branches\Actions;

use App\Core\Auth\CorePermissions;
use App\Core\Branches\Branch;
use App\Core\Tenancy\PlanLimits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Adds and edits branches. A new active branch, or switching an inactive
 * one back on, counts against the plan's branch limit. The Branch model
 * audits every change.
 */
final class SaveBranch
{
    public function __construct(private readonly PlanLimits $limits) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): Branch
    {
        Gate::authorize(CorePermissions::BRANCHES_MANAGE);

        return DB::connection('clinic')->transaction(function () use ($data): Branch {
            if ((bool) ($data['is_active'] ?? true)) {
                $this->limits->checkNewBranch();
            }

            return Branch::create($data);
        });
    }

    /** @param array<string, mixed> $data */
    public function update(Branch $branch, array $data): Branch
    {
        Gate::authorize(CorePermissions::BRANCHES_MANAGE);

        return DB::connection('clinic')->transaction(function () use ($branch, $data): Branch {
            if (! $branch->is_active && (bool) ($data['is_active'] ?? false)) {
                $this->limits->checkNewBranch();
            }

            $branch->fill($data)->save();

            return $branch;
        });
    }
}
