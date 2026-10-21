<?php

// Application settings (see .env.example for each variable).
return [
    // Admin session lifetime in hours (sliding; never more than 7 days in total).
    'admin_session_hours' => (int) env('ADMIN_SESSION_HOURS', 12),

    // Participant session lifetime in hours (sliding).
    'participant_session_hours' => (int) env('PARTICIPANT_SESSION_HOURS', 72),

    // 64 hex chars (32 bytes) used to encrypt access passwords. When empty, a key file is
    // created next to the database (storage/app/private/clave-cifrado.key).
    'access_password_key' => env('ACCESS_PASSWORD_KEY'),

    // Session cookies are Secure everywhere except the local environment.
    'secure_cookies' => (bool) env('SECURE_COOKIES', env('APP_ENV', 'production') !== 'local'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        'timeout_ms' => (int) env('AI_TIMEOUT_MS', 180000),
    ],
];
