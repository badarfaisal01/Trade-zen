<?php
declare(strict_types=1);

return [
    'name' => '55AK Trade',
    'tagline' => 'Smart market trading platform',
    'url' => 'https://trading.55ak.xyz',
    'timezone' => 'Asia/Kolkata',
    'environment' => getenv('APP_ENV') ?: 'production',
    'debug' => filter_var(getenv('APP_DEBUG') ?: '0', FILTER_VALIDATE_BOOLEAN),
    'trust_cloudflare_ip_header' => filter_var(getenv('TRUST_CLOUDFLARE_IP_HEADER') ?: '0', FILTER_VALIDATE_BOOLEAN),
    'session_name' => 'TRADE55AK',
    'currency' => 'USD',
    'demo_starting_balance' => 0.00,
    'practice_starting_balance' => 1000.00,
    'trade_durations' => [5, 10, 15, 30, 60, 120, 300],
    'version' => '4.5.0',
];
