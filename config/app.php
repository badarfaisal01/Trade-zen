<?php
declare(strict_types=1);

/**
 * Main Application Configuration
 * Dynamically populated from environment variables with safe defaults.
 */

return [
    'name' => (string)env('APP_NAME', '55AK Trade'),
    'tagline' => (string)env('APP_TAGLINE', 'Smart market trading platform'),
    'url' => (string)env('APP_URL', 'http://localhost'),
    'timezone' => (string)env('APP_TIMEZONE', 'Asia/Kolkata'),
    'environment' => (string)env('APP_ENV', 'development'),
    'debug' => (bool)env('APP_DEBUG', true),
    'trust_cloudflare_ip_header' => (bool)env('TRUST_CLOUDFLARE_IP_HEADER', false),
    'session_name' => (string)env('SESSION_NAME', 'TRADE55AK'),
    'session_lifetime' => (int)env('SESSION_LIFETIME', 120),
    'currency' => (string)env('DEFAULT_CURRENCY', 'USD'),
    'demo_starting_balance' => (float)env('DEMO_STARTING_BALANCE', 0.00),
    'practice_starting_balance' => (float)env('PRACTICE_STARTING_BALANCE', 1000.00),
    'trade_durations' => [5, 10, 15, 30, 60, 120, 300],
    'version' => (string)env('APP_VERSION', '4.5.0'),
];
