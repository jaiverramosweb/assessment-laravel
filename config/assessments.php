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

    // AI analysis. These are defaults: the provider, models and API keys saved from the admin
    // panel ("Configuración") take precedence (App\Services\Ai\AiSettings).
    'ai_provider' => env('AI_PROVIDER', 'anthropic'),

    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        // Per-call timeout, shared by every provider.
        'timeout_ms' => (int) env('AI_TIMEOUT_MS', 180000),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL'),
    ],
];
