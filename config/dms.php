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

    // Messaging module. The SMS gateway is set once for the whole
    // installation, so a clinic cannot point the server at an address of its
    // own choosing. Each clinic switches SMS on or off for itself.
    'messaging' => [
        'sms' => [
            // "log": test mode, messages are recorded but not sent.
            // "http": a bulk SMS provider reached by a web request.
            // "off": no SMS at all.
            'driver' => env('SMS_DRIVER', 'log'),
            'url' => env('SMS_HTTP_URL'),
            'method' => env('SMS_HTTP_METHOD', 'post'),
            // Sent as form fields (or as the query for "get"). Placeholders:
            // {api_key}, {sender}, {to}, {message}.
            'params' => env('SMS_HTTP_PARAMS', 'api_key={api_key}&senderid={sender}&number={to}&message={message}'),
            'api_key' => env('SMS_API_KEY'),
            'sender' => env('SMS_SENDER_ID'),
            // When set, the gateway's reply must contain this text.
            'success' => env('SMS_HTTP_SUCCESS_TEXT'),
        ],
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

        // Subscriptions (Phase 8b): a new clinic's free trial, the days after
        // the end date when everything still works, and when to start reminding.
        'trial_days' => (int) env('CLINIC_TRIAL_DAYS', 30),
        'grace_days' => (int) env('CLINIC_GRACE_DAYS', 7),
        'remind_days' => (int) env('CLINIC_REMIND_DAYS', 7),
    ],

    // The platform console (Softorio's own admin area). It answers only on
    // this address, for example admin.softorio.com; empty switches it off.
    'platform' => [
        'domain' => env('PLATFORM_DOMAIN'),

        // Printed on subscription invoices, and shown to clinic owners.
        'billing' => [
            'name' => env('PLATFORM_BILL_NAME', 'Softorio'),
            'address' => env('PLATFORM_BILL_ADDRESS'),
            'phone' => env('PLATFORM_BILL_PHONE'),
            'email' => env('PLATFORM_BILL_EMAIL'),
            // How to pay, for example "bKash merchant 01XXXXXXXXX, reference: the invoice number".
            'how_to_pay' => env('PLATFORM_BILL_HOW_TO_PAY'),
        ],
    ],

];
