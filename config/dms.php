<?php

return [

    // Core version. Module manifests declare which core versions they support.
    'version' => '1.0.0',

    // Where module folders live.
    'modules_path' => env('DMS_MODULES_PATH', base_path('modules')),

    'backup' => [
        // Folder backups are written to. Empty switches the nightly backup off.
        'path' => env('BACKUP_PATH'),

        // When set, backups are encrypted with this passphrase (AES-256).
        // Without it nobody can read the backup, so keep a copy somewhere
        // other than this server.
        'passphrase' => env('BACKUP_PASSPHRASE'),

        // Backups older than this are deleted from the backup folder.
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),
    ],

    // TEMPORARY, for the trial period: lets the clinic owner see and apply
    // pending database updates from the portal. Off unless DMS_PORTAL_UPDATES
    // is true. To remove the feature, see "Temporary: database updates from
    // the portal" in the README.
    'portal_updates' => [
        'enabled' => (bool) env('DMS_PORTAL_UPDATES', false),
    ],

    'clinics' => [
        // Each clinic database is named <prefix><clinic id>.
        'database_prefix' => env('CLINIC_DB_PREFIX', 'dms_clinic_'),

        // MySQL only: give every clinic database its own MySQL user.
        'separate_database_users' => (bool) env('CLINIC_DB_SEPARATE_USERS', true),

        // MySQL only: host part of the per-clinic MySQL user.
        'database_user_host' => env('CLINIC_DB_USER_HOST', '127.0.0.1'),

        // SQLite only (tests): folder holding one file per clinic.
        'sqlite_path' => env('CLINIC_SQLITE_PATH', database_path('clinics')),

        // Plan given to a clinic created without one.
        'default_plan' => env('CLINIC_DEFAULT_PLAN', 'full'),

        'default_timezone' => 'Asia/Dhaka',
    ],

];
