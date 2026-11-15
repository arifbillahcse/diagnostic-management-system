<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Auth\CorePermissions;
use App\Core\Auth\PermissionChecker;
use App\Core\Auth\Role;
use App\Core\Branches\Branch;
use App\Core\Modules\ModuleManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class PermissionTest extends TestCase
{
    public function test_the_owner_has_every_core_permission(): void
    {
        $this->useClinic();
        $owner = $this->owner();

        foreach (array_keys(CorePermissions::all()) as $permission) {
            $this->assertTrue($owner->can($permission), $permission);
        }
    }

    public function test_default_roles_get_their_default_grants_and_nothing_more(): void
    {
        $this->useClinic();

        $admin = $this->userWithRoles('admin');
        $accountant = $this->userWithRoles('accountant');
        $receptionist = $this->userWithRoles('receptionist');

        $this->assertTrue($admin->can(CorePermissions::USERS_MANAGE));
        $this->assertTrue($admin->can(CorePermissions::MODULES_MANAGE));

        $this->assertTrue($accountant->can(CorePermissions::AUDIT_VIEW));
        $this->assertFalse($accountant->can(CorePermissions::USERS_MANAGE));

        foreach (array_keys(CorePermissions::all()) as $permission) {
            $this->assertFalse($receptionist->can($permission), $permission);
        }
    }

    public function test_a_user_without_roles_or_an_inactive_user_is_denied(): void
    {
        $this->useClinic();

        $this->assertFalse($this->userWithRoles()->can(CorePermissions::AUDIT_VIEW));

        $admin = $this->userWithRoles('admin');
        $admin->forceFill(['is_active' => false])->save();
        $this->assertFalse($admin->can(CorePermissions::USERS_MANAGE));

        $owner = $this->owner();
        $owner->forceFill(['is_active' => false])->save();
        $this->assertFalse($owner->can(CorePermissions::USERS_MANAGE));
    }

    public function test_an_unknown_permission_is_denied_even_for_the_owner(): void
    {
        $this->useClinic();

        $this->assertFalse(app(PermissionChecker::class)->allows($this->owner(), 'core.nothing.real'));
        $this->assertFalse($this->owner()->can('core.nothing.real'));
    }

    public function test_a_modules_permissions_are_denied_while_the_module_is_disabled(): void
    {
        $this->useModuleFixtures();
        $this->useClinic();
        $owner = $this->owner();
        $admin = $this->userWithRoles('admin');
        $modules = app(ModuleManager::class);

        $this->assertFalse($owner->can('beta.items.manage'));

        $modules->enable('alpha');
        $modules->enable('beta');
        app(PermissionChecker::class)->flush();

        $this->assertTrue($owner->can('alpha.things.view'));
        $this->assertTrue($owner->can('beta.items.manage'));
        $this->assertTrue($admin->can('beta.items.manage'), 'admin receives the module default grant');
        $this->assertFalse($admin->can('alpha.things.view'), 'no default grant was declared for this one');

        $modules->disable('beta');

        $this->assertFalse($owner->can('beta.items.manage'));
        $this->assertFalse($admin->can('beta.items.manage'));
        $this->assertTrue($owner->can('alpha.things.view'));

        $modules->enable('beta');

        $this->assertTrue($admin->can('beta.items.manage'), 'the grant survives a disable and re-enable');
    }

    public function test_a_role_can_be_limited_to_one_branch(): void
    {
        $this->useClinic();
        $main = Branch::first();
        $second = Branch::create(['code' => 'B2', 'name' => 'Second branch']);

        $user = $this->userWithRoles();
        DB::connection('clinic')->table('user_roles')->insert([
            'user_id' => $user->id,
            'role_id' => Role::where('key', 'admin')->value('id'),
            'branch_id' => $second->id,
        ]);
        app(PermissionChecker::class)->flush();

        $this->assertTrue(Gate::forUser($user)->allows(CorePermissions::USERS_MANAGE, $second));
        $this->assertFalse(Gate::forUser($user)->allows(CorePermissions::USERS_MANAGE, $main));
        $this->assertTrue(Gate::forUser($user)->allows(CorePermissions::USERS_MANAGE), 'no branch asked: any branch counts');

        $everywhere = $this->userWithRoles('admin');
        $this->assertTrue(Gate::forUser($everywhere)->allows(CorePermissions::USERS_MANAGE, $main));
        $this->assertTrue(Gate::forUser($everywhere)->allows(CorePermissions::USERS_MANAGE, $second->id));
    }

    public function test_grants_do_not_carry_over_between_clinics(): void
    {
        $alpha = $this->useClinic('alpha');
        $alphaAdmin = $this->userWithRoles('admin');
        $this->assertTrue($alphaAdmin->can(CorePermissions::USERS_MANAGE));

        // Same numeric user ID in another clinic, with no roles there.
        $this->useClinic('beta');
        $betaUser = $this->userWithRoles();
        $this->assertSame($alphaAdmin->id, $betaUser->id);
        $this->assertFalse($betaUser->can(CorePermissions::USERS_MANAGE));
    }
}
