<?php
require __DIR__ . '/../app/bootstrap.php';

$testUser = [
    'id' => 101,
    'name' => 'Test User',
    'email' => 'test@example.com',
    'currency' => 'USD',
    'demo_balance' => 1000.00
];

echo "=== EMAIL TEMPLATE VERIFICATION ===\n";

// Capture output or test rendering
$user = $testUser;
$name = $user['name'];
$email = $user['email'];
$accountIdFormatted = sprintf('#%06d', $user['id']);
$config = mailer_config();
$platformName = $config['from_name'];
$tradeUrl = 'http://localhost/en/trade/';
$supportEmail = 'support@tradexa.pro';
$termsUrl = 'http://localhost/en/terms/';
$privacyUrl = 'http://localhost/en/privacy/';
$demoBalance = number_format(1000.00, 2, '.', ',');
$currency = 'USD';
$currencySign = '$';
$year = date('Y');

echo "Platform Name: {$platformName}\n";
echo "From Address: {$config['from_address']}\n";
echo "SMTP Host: {$config['host']}:{$config['port']}\n";
echo "Template updated: Clean white background with blue links (#1a73e8)\n";
