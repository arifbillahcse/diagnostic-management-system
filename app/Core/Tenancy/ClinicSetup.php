<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

use App\Core\Audit\AuditLogger;
use App\Core\Auth\CorePermissions;
use App\Core\Auth\PermissionRegistry;
use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Branches\Branch;
use App\Core\Modules\Entitlements;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleRegistry;
use App\Core\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Fills a freshly migrated clinic database with what every clinic starts
 * with: one branch, the default roles, the owner, and the default modules.
 * Runs with the clinic already active.
 */
final class ClinicSetup
{
    public function __construct(
        private readonly PermissionRegistry $permissions,
        private readonly Settings $settings,
        private readonly AuditLogger $audit,
        private readonly ModuleRegistry $registry,
        private readonly ModuleManager $modules,
        private readonly Entitlements $entitlements,
    ) {}

    public function run(Clinic $clinic, string $ownerName, string $ownerEmail, string $ownerPassword): User
    {
        $owner = DB::connection('clinic')->transaction(function () use ($clinic, $ownerName, $ownerEmail, $ownerPassword): User {
            $branch = Branch::create(['code' => 'MAIN', 'name' => 'Main branch']);

            $this->permissions->sync();

            foreach (CorePermissions::defaultRoles() as $key => $name) {
                Role::firstOrCreate(['key' => $key], ['name' => $name, 'is_system' => $key === Role::OWNER]);
            }

            $this->permissions->applyDefaultGrants(CorePermissions::defaultGrants());

            $owner = User::create([
                'name' => $ownerName,
                'email' => strtolower(trim($ownerEmail)),
                'password' => $ownerPassword,
                'is_active' => true,
                'default_branch_id' => $branch->id,
            ]);

            DB::connection('clinic')->table('user_roles')->insert([
                'user_id' => $owner->id,
                'role_id' => Role::where('key', Role::OWNER)->value('id'),
                'branch_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->settings->set('clinic.name', $clinic->name);
            $this->audit->record('clinic.created', null, [], [], ['name' => $clinic->name, 'owner' => $owner->email]);

            return $owner;
        });

        // Registry order guarantees a module's requirements come first.
        foreach ($this->registry->all() as $key => $manifest) {
            if ($manifest->defaultEnabled && $this->entitlements->allows($key)) {
                $this->modules->enable($key);
            }
        }

        return $owner;
    }
}
