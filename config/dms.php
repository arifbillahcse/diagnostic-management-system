<?php

return [

    // Core version. Module manifests declare which core versions they support.
    'version' => '1.0.0',

    // Where module folders live.
    'modules_path' => env('DMS_MODULES_PATH', base_path('modules')),

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
