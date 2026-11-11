<?php

return [
    // Rent Manager 12 Web API. Credentials live only in the server's .env file.
    'rent_manager' => [
        'base_url' => env('RM_API_BASE_URL', 'https://axiom.api.rentmanager.com'),
        'username' => env('RM_API_USER'),
        'password' => env('RM_API_PASSWORD'),
        // Endpoint names. Confirm against /Help once logged in; adjust here without code changes.
        'endpoints' => [
            'auth' => env('RM_ENDPOINT_AUTH', '/Authentication/AuthorizeUser'),
            'issues' => env('RM_ENDPOINT_ISSUES', '/ServiceManagerIssues'),
            'tenants' => env('RM_ENDPOINT_TENANTS', '/Tenants'),
            'properties' => env('RM_ENDPOINT_PROPERTIES', '/Properties'),
            'units' => env('RM_ENDPOINT_UNITS', '/Units'),
            'issue_attachments' => env('RM_ENDPOINT_ISSUE_ATTACHMENTS', '/ServiceManagerIssues/{id}/Attachments'),
        ],
        'token_ttl_minutes' => (int) env('RM_TOKEN_TTL_MINUTES', 50),
        'enabled' => (bool) env('RM_SYNC_ENABLED', false),
    ],

    // Shared secret the axiom-properties.com site sends in X-Axiom-Key.
    'intake_key' => env('INTAKE_API_KEY'),

    'sms' => [
        'driver' => env('SMS_DRIVER', 'log'), // log | twilio
        'from' => env('SMS_FROM_NUMBER'),     // the one Axiom business number, E.164
        'office_phone' => env('OFFICE_PHONE'),
        'twilio' => [
            'account_sid' => env('TWILIO_ACCOUNT_SID'),
            'auth_token' => env('TWILIO_AUTH_TOKEN'),
            'validate_signature' => (bool) env('TWILIO_VALIDATE_SIGNATURE', true),
        ],
    ],

    'photos' => [
        // Cloudflare R2 (S3-compatible). Photos never touch Bluehost's disk.
        'r2' => [
            'account_id' => env('R2_ACCOUNT_ID'),
            'access_key' => env('R2_ACCESS_KEY_ID'),
            'secret_key' => env('R2_SECRET_ACCESS_KEY'),
            'bucket' => env('R2_BUCKET', 'axiom-maintenance-photos'),
        ],
        'max_per_request' => 6,
        'purge_days_after_close' => (int) env('PHOTO_PURGE_DAYS', 30),
    ],

    'ai' => [
        'enabled' => (bool) env('AI_TRIAGE_ENABLED', false),
        'model' => env('AI_TRIAGE_MODEL', 'claude-opus-5-5'),
        'effort' => env('AI_TRIAGE_EFFORT', 'low'),
        'min_confidence' => (float) env('AI_MIN_CONFIDENCE', 0.7),
    ],

    'scheduling' => [
        'window_minutes' => 120,           // tenant-facing arrival window
        'horizon_days' => 5,               // how far ahead to look for slots
        'offers_per_request' => 3,
        'offer_reply_hours' => 24,          // flag if no reply after this
        'target_business_days' => 3,       // flag if no slot within this
        'auto_book_with_permission' => true, // routine + permission to enter: book without asking
        'travel_speed_kmh' => 40,          // straight-line estimate until a maps API is added
    ],
];
