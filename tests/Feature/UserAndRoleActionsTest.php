<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Audit\AuditLog;
use App\Core\Auth\Actions\SaveRole;
use App\Core\Auth\Actions\SaveUser;
use App\Core\Auth\CorePermissions;
use App\Core\Auth\LastOwnerException;
use App\Core\Auth\Role;
use App\Core\Auth\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class UserAndRoleActionsTest extends TestCase
{
    private function roleId(string $key): int
    {
        return (int) Role::where('key', $key)->value('id');
    }

    public function test_creating_a_user_assigns_roles_and_writes_an_audit_entry(): void
    {
        $this->useClinic();
        $this->actingAs($this->owner());

        $user = app(SaveUser::class)->create([
            'name' => 'Rina Akter',
            'email' => '  Rina@Example.TEST ',
            'password' => 'a-strong-password',
            'is_active' => true,
            'role_ids' => [$this->roleId('receptionist')],
        ]);

        $this->assertSame('rina@example.test', $user->email);
        $this->assertTrue(Hash::check('a-strong-password', $user->password));
        $this->assertTrue($user->fresh()->hasRole('receptionist'));
        $this->assertSame(26, strlen($user->public_id));

        $entry = AuditLog::where('action', 'user.created')->sole();
        $this->assertSame($this->owner()->id, $entry->actor_id);
        $this->assertSame($user->id, $entry->entity_id);
        $this->assertSame(['Receptionist'], $entry->after['roles']);
        $this->assertStringNotContainsString('a-strong-password', json_encode($entry->getAttributes()));
        $this->assertArrayNotHasKey('password', $entry->after);
    }

    public function test_updating_a_user_records_only_what_changed(): void
    {
        $this->useClinic();
        $this->actingAs($this->owner());
        $save = app(SaveUser::class);

        $user = $save->create(['name' => 'Karim', 'email' => 'karim@example.test', 'password' => 'a-strong-password', 'role_ids' => [$this->roleId('receptionist')]]);
        $original = $user->password;

        $save->update($user, ['name' => 'Karim Uddin', 'email' => 'karim@example.test', 'password' => null, 'role_ids' => [$this->roleId('accountant')]]);

        $entry = AuditLog::where('action', 'user.updated')->sole();
        $this->assertSame(['name' => 'Karim', 'roles' => ['Receptionist']], $entry->before);
        $this->assertSame(['name' => 'Karim Uddin', 'roles' => ['Accountant']], $entry->after);
        $this->assertSame($original, $user->fresh()->password, 'an empty password leaves the old one in place');

        $save->update($user, ['password' => 'another-strong-password']);
        $entry = AuditLog::where('action', 'user.updated')->latest('id')->first();
        $this->assertSame(['password' => '[hidden]'], $entry->after);
        $this->assertTrue(Hash::check('another-strong-password', $user->fresh()->password));
        $this->assertTrue($user->fresh()->hasRole('accountant'), 'roles are untouched when not submitted');

        $count = AuditLog::count();
        $save->update($user, ['name' => 'Karim Uddin']);
        $this->assertSame($count, AuditLog::count(), 'no change, no audit entry');
    }

    public function test_only_users_with_the_permission_can_manage_users(): void
    {
        $this->useClinic();
        $this->actingAs($this->userWithRoles('receptionist'));

        $this->expectException(AuthorizationException::class);
        app(SaveUser::class)->create(['name' => 'X', 'email' => 'x@example.test', 'password' => 'a-strong-password']);
    }

    public function test_the_last_active_owner_cannot_be_deactivated_or_demoted(): void
    {
        $this->useClinic();
        $owner = $this->owner();
        $this->actingAs($owner);
        $save = app(SaveUser::class);

        try {
            $save->update($owner, ['is_active' => false]);
            $this->fail('Deactivating the last owner should be refused.');
        } catch (LastOwnerException) {
            $this->assertTrue($owner->fresh()->is_active);
        }

        try {
            $save->update($owner, ['role_ids' => [$this->roleId('admin')]]);
            $this->fail('Demoting the last owner should be refused.');
        } catch (LastOwnerException) {
            $this->assertTrue($owner->fresh()->hasRole('owner'));
        }

        // With a second owner, the first can step down.
        $second = $save->create(['name' => 'Second', 'email' => 'second@example.test', 'password' => 'a-strong-password', 'role_ids' => [$this->roleId('owner')]]);
        $save->update($owner, ['role_ids' => [$this->roleId('admin')]]);

        $this->assertFalse($owner->fresh()->hasRole('owner'));
        $this->assertTrue($second->fresh()->hasRole('owner'));
    }

    public function test_roles_can_be_created_edited_and_deleted_with_an_audit_trail(): void
    {
        $this->useClinic();
        $this->actingAs($this->owner());
        $save = app(SaveRole::class);

        $role = $save->create('Front Desk Lead', [CorePermissions::AUDIT_VIEW, 'not.a.real.permission']);
        $this->assertSame('front_desk_lead', $role->key);
        $this->assertSame([CorePermissions::AUDIT_VIEW], $role->permissions()->pluck('key')->all());

        $duplicate = $save->create('Front Desk Lead', []);
        $this->assertSame('front_desk_lead_2', $duplicate->key);

        $lead = $this->userWithRoles('front_desk_lead');
        $this->assertTrue($lead->can(CorePermissions::AUDIT_VIEW));

        $save->update($role, 'Desk Lead', [CorePermissions::BRANCHES_MANAGE]);
        $this->assertFalse($lead->can(CorePermissions::AUDIT_VIEW));
        $this->assertTrue($lead->can(CorePermissions::BRANCHES_MANAGE));

        $entry = AuditLog::where('action', 'role.updated')->sole();
        $this->assertSame('Front Desk Lead', $entry->before['name']);
        $this->assertSame([CorePermissions::BRANCHES_MANAGE], $entry->after['permissions']);

        try {
            $save->delete($role);
            $this->fail('A role in use should not be deletable.');
        } catch (DomainException) {
            $this->assertTrue(Role::whereKey($role->id)->exists());
        }

        $save->delete($duplicate);
        $this->assertFalse(Role::whereKey($duplicate->id)->exists());
        $this->assertSame(1, AuditLog::where('action', 'role.deleted')->count());
    }

    public function test_the_owner_role_cannot_be_edited_or_deleted(): void
    {
        $this->useClinic();
        $this->actingAs($this->owner());
        $ownerRole = Role::where('key', 'owner')->first();

        foreach ([
            fn () => app(SaveRole::class)->update($ownerRole, 'Boss', []),
            fn () => app(SaveRole::class)->delete($ownerRole),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('The owner role should be protected.');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame('Owner', $ownerRole->fresh()->name);
        $this->assertSame(1, User::count());
    }
}
