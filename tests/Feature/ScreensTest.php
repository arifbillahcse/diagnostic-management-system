<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit\AuditLog;
use App\Core\Auth\CorePermissions;
use App\Core\Auth\Role;
use App\Core\Auth\User;
use App\Core\Modules\ModuleManager as Modules;
use App\Core\Modules\ModuleState;
use App\Core\Settings\Settings;
use App\Core\Tenancy\ClinicManager;
use App\Core\Tenancy\ClinicStatus;
use App\Core\Tenancy\Http\EnsureSessionBelongsToClinic;
use App\Filament\Pages\ManageSettings;
use App\Filament\Pages\ModuleManager;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\TestCase;

final class ScreensTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_an_unknown_address_is_not_found(): void
    {
        $this->createClinic('alpha');

        $this->get('http://nobody.test/login')->assertNotFound();
    }

    public function test_a_suspended_clinic_is_blocked_with_an_explanation(): void
    {
        $clinic = $this->createClinic('alpha');
        $clinic->update(['status' => ClinicStatus::Suspended]);

        $this->get('http://alpha.test/login')
            ->assertForbidden()
            ->assertSee('Alpha Diagnostics is not available');
    }

    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->createClinic('alpha');

        $this->get('http://alpha.test/login')->assertOk();
        $this->get('http://alpha.test/')->assertRedirect('http://alpha.test/login');
        $this->get('http://alpha.test/users')->assertRedirect('http://alpha.test/login');
    }

    public function test_signing_in_ties_the_session_to_the_clinic_and_is_audited(): void
    {
        $clinic = $this->useClinic('alpha');

        Livewire::test(Login::class)
            ->fillForm(['email' => 'owner@alpha.test', 'password' => 'wrong-password'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);

        $this->assertGuest();
        $this->assertSame('owner@alpha.test', AuditLog::where('action', 'auth.failed')->sole()->context['email']);

        Livewire::test(Login::class)
            ->fillForm(['email' => 'owner@alpha.test', 'password' => self::PASSWORD])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($this->owner());
        $this->assertSame($clinic->id, session(EnsureSessionBelongsToClinic::SESSION_KEY));
        $this->assertNotNull($this->owner()->last_login_at);
        $this->assertSame(1, AuditLog::where('action', 'auth.login')->count());
    }

    public function test_an_inactive_user_cannot_open_the_panel(): void
    {
        $this->useClinic('alpha');
        $user = $this->userWithRoles('admin');
        $user->forceFill(['is_active' => false])->save();

        $this->signIn($user)->get('http://alpha.test/')->assertForbidden();
    }

    public function test_a_session_from_one_clinic_does_not_work_on_another(): void
    {
        $this->createClinic('beta');
        $this->useClinic('alpha');

        // Signed in at alpha, then the same session is presented to beta.
        $this->signIn($this->owner())
            ->get('http://beta.test/')
            ->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_the_owner_can_open_every_screen(): void
    {
        $this->useClinic('alpha');
        $owner = $this->owner();
        $adminRole = Role::where('key', 'admin')->first();

        foreach ([
            '/', '/users', '/users/create', "/users/{$owner->public_id}/edit",
            '/roles', '/roles/create', "/roles/{$adminRole->id}/edit",
            '/branches', '/audit-log', '/settings', '/modules',
        ] as $path) {
            $this->signIn($owner)->get("http://alpha.test{$path}")->assertOk();
        }
    }

    public function test_the_menu_and_screens_follow_the_users_permissions(): void
    {
        $this->useClinic('alpha');
        $receptionist = $this->userWithRoles('receptionist');
        $accountant = $this->userWithRoles('accountant');

        $this->signIn($receptionist)->get('http://alpha.test/')
            ->assertOk()
            ->assertDontSee('Audit log')
            ->assertDontSee('href="http://alpha.test/modules"', false);

        foreach (['/users', '/users/create', '/roles', '/branches', '/audit-log', '/settings', '/modules'] as $path) {
            $this->signIn($receptionist)->get("http://alpha.test{$path}")->assertForbidden();
        }

        $this->signIn($accountant)->get('http://alpha.test/audit-log')->assertOk();
        $this->signIn($accountant)->get('http://alpha.test/users')->assertForbidden();
    }

    public function test_the_owner_role_has_no_edit_screen(): void
    {
        $this->useClinic('alpha');
        $ownerRole = Role::where('key', 'owner')->first();

        $this->signIn($this->owner())->get("http://alpha.test/roles/{$ownerRole->id}/edit")->assertForbidden();
    }

    public function test_a_user_can_be_created_and_edited_from_the_screen(): void
    {
        $this->useClinic('alpha');
        $this->actingAs($this->owner());
        $receptionist = Role::where('key', 'receptionist')->value('id');

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Salma Begum',
                'email' => 'salma@alpha.test',
                'password' => 'short',
                'default_branch_id' => 1,
                'role_ids' => [$receptionist],
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Salma Begum',
                'email' => 'salma@alpha.test',
                'password' => 'a-strong-password',
                'default_branch_id' => 1,
                'role_ids' => [$receptionist],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $salma = User::where('email', 'salma@alpha.test')->sole();
        $this->assertTrue($salma->hasRole('receptionist'));
        $this->assertSame(1, AuditLog::where('action', 'user.created')->count());

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Copy', 'email' => 'salma@alpha.test', 'password' => 'a-strong-password', 'default_branch_id' => 1])
            ->call('create')
            ->assertHasFormErrors(['email']);

        Livewire::test(EditUser::class, ['record' => $salma->public_id])
            ->assertFormSet(['name' => 'Salma Begum', 'role_ids' => [$receptionist]])
            ->fillForm(['name' => 'Salma B.', 'is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Salma B.', $salma->fresh()->name);
        $this->assertFalse($salma->fresh()->is_active);
        $this->assertTrue($salma->fresh()->hasRole('receptionist'));
    }

    public function test_the_screen_refuses_to_deactivate_the_last_owner(): void
    {
        $this->useClinic('alpha');
        $owner = $this->owner();
        $this->actingAs($owner);

        Livewire::test(EditUser::class, ['record' => $owner->public_id])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertNotified('Not saved');

        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_roles_are_managed_from_the_screen(): void
    {
        $this->useClinic('alpha');
        $this->actingAs($this->owner());

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'Supervisor', 'permission_keys' => [CorePermissions::AUDIT_VIEW]])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::where('key', 'supervisor')->sole();
        $this->assertSame([CorePermissions::AUDIT_VIEW], $role->permissions()->pluck('key')->all());

        Livewire::test(EditRole::class, ['record' => $role->id])
            ->assertFormSet(['name' => 'Supervisor', 'permission_keys' => [CorePermissions::AUDIT_VIEW]])
            ->fillForm(['permission_keys' => [CorePermissions::AUDIT_VIEW, CorePermissions::BRANCHES_MANAGE]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            [CorePermissions::AUDIT_VIEW, CorePermissions::BRANCHES_MANAGE],
            $role->permissions()->pluck('key')->all(),
        );
    }

    public function test_editing_a_role_keeps_grants_of_modules_that_are_switched_off(): void
    {
        $this->useClinic('alpha');
        $this->actingAs($this->owner());
        $modules = app(Modules::class);
        $admin = Role::where('key', 'admin')->first();

        $modules->enable('demo');
        $this->assertContains('demo.notes.manage', $admin->permissions()->pluck('key')->all());

        $modules->disable('demo');

        Livewire::test(EditRole::class, ['record' => $admin->id])
            ->fillForm(['permission_keys' => [CorePermissions::USERS_MANAGE]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertEqualsCanonicalizing(
            [CorePermissions::USERS_MANAGE, 'demo.notes.manage'],
            $admin->permissions()->pluck('key')->all(),
        );
    }

    public function test_clinic_settings_are_saved_from_the_screen(): void
    {
        $this->useClinic('alpha');
        $this->actingAs($this->owner());

        Livewire::test(ManageSettings::class)
            ->assertFormSet(['name' => 'Alpha Diagnostics'])
            ->fillForm(['name' => '', 'phone' => '01711-000000'])
            ->call('save')
            ->assertHasFormErrors(['name']);

        Livewire::test(ManageSettings::class)
            ->fillForm(['name' => 'Alpha Diagnostic Center', 'phone' => '01711-000000'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Settings saved');

        $this->assertSame('Alpha Diagnostic Center', app(Settings::class)->get('clinic.name'));
        $this->assertSame('01711-000000', app(Settings::class)->get('clinic.phone'));

        $this->signIn($this->owner())->get('http://alpha.test/')->assertSee('Alpha Diagnostic Center');
    }

    public function test_modules_are_switched_from_the_screen(): void
    {
        $this->useClinic('alpha');
        $this->actingAs($this->owner());

        Livewire::test(ModuleManager::class)
            ->assertSee('Demo notes')
            ->assertSee('Switch on')
            ->call('enable', 'demo')
            ->assertNotified('Module switched on');

        $this->assertTrue(app(ModuleState::class)->isEnabled('demo'));

        Livewire::test(ModuleManager::class)
            ->assertSee('Switch off')
            ->call('disable', 'demo')
            ->assertNotified('Module switched off');

        $this->assertFalse(app(ModuleState::class)->isEnabled('demo'));

        Livewire::test(ModuleManager::class)
            ->call('enable', 'no-such-module')
            ->assertNotified('Not changed');
    }

    public function test_a_user_without_the_permission_cannot_use_the_module_screen(): void
    {
        $this->useClinic('alpha');
        $this->actingAs($this->userWithRoles('receptionist'));

        Livewire::test(ModuleManager::class)->assertForbidden();

        $this->assertFalse(app(ModuleState::class)->isEnabled('demo'));
    }

    public function test_screens_show_only_the_current_clinics_data(): void
    {
        $alpha = $this->useClinic('alpha');
        $this->userWithRoles('receptionist')->update(['name' => 'Only At Alpha']);

        $beta = $this->createClinic('beta');
        app(ClinicManager::class)->activate($beta);

        $this->signIn($this->owner())->get('http://beta.test/users')
            ->assertOk()
            ->assertSee('Beta Owner')
            ->assertDontSee('Only At Alpha');
    }
}
