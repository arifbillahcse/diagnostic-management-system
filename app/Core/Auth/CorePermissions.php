<?php

declare(strict_types=1);

namespace App\Core\Auth;

/**
 * Permissions and default roles that belong to the core.
 * Modules declare their own in their entry class.
 */
final class CorePermissions
{
    public const USERS_MANAGE = 'core.users.manage';

    public const ROLES_MANAGE = 'core.roles.manage';

    public const BRANCHES_MANAGE = 'core.branches.manage';

    public const SETTINGS_MANAGE = 'core.settings.manage';

    public const MODULES_MANAGE = 'core.modules.manage';

    public const AUDIT_VIEW = 'core.audit.view';

    public const PATIENTS_VIEW = 'core.patients.view';

    public const PATIENTS_CREATE = 'core.patients.create';

    public const PATIENTS_UPDATE = 'core.patients.update';

    /** @return array<string, string> key => description */
    public static function all(): array
    {
        return [
            self::USERS_MANAGE => 'Create, edit, and deactivate users',
            self::ROLES_MANAGE => 'Create and edit roles and their permissions',
            self::BRANCHES_MANAGE => 'Create and edit branches',
            self::SETTINGS_MANAGE => 'Change clinic settings',
            self::MODULES_MANAGE => 'Switch modules on and off',
            self::AUDIT_VIEW => 'View the audit log',
            self::PATIENTS_VIEW => 'View patients',
            self::PATIENTS_CREATE => 'Register patients',
            self::PATIENTS_UPDATE => 'Edit patient details',
        ];
    }

    /**
     * Roles every new clinic starts with. The clinic can rename and edit
     * all of them except the owner.
     *
     * @return array<string, string> role key => name
     */
    public static function defaultRoles(): array
    {
        return [
            Role::OWNER => 'Owner',
            'admin' => 'Admin',
            'receptionist' => 'Receptionist',
            'lab_technician' => 'Lab technician',
            'verifier' => 'Verifier',
            'accountant' => 'Accountant',
        ];
    }

    /** @return array<string, list<string>> role key => permission keys */
    public static function defaultGrants(): array
    {
        return [
            'admin' => array_keys(self::all()),
            'accountant' => [self::AUDIT_VIEW, self::PATIENTS_VIEW],
            'receptionist' => [self::PATIENTS_VIEW, self::PATIENTS_CREATE, self::PATIENTS_UPDATE],
            'lab_technician' => [self::PATIENTS_VIEW],
            'verifier' => [self::PATIENTS_VIEW],
        ];
    }
}
