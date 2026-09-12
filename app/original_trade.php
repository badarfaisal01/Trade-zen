<?php
declare(strict_types=1);

/**
 * Runtime settings expected by the supplied React trade-room bundles.
 *
 * The captured page shipped a real user's private broker session in this
 * object. This replacement contains only data from the signed-in local
 * account and a non-secret marker consumed by the same-origin JS bridge.
 */
function original_trade_settings(array $user): array
{
    $minimum = (float)setting('minimum_trade_amount', 1);
    $maximum = (float)setting('maximum_trade_amount', 1000);
    $accountType = trade_account_type();
    $liveBalance = (float)$user['demo_balance'];
    $demoBalance = (float)$user['demo_trade_balance'];
    $balance = $accountType === 'demo' ? $demoBalance : $liveBalance;

    // Build modals with market assets
    $assets = market_assets(true);
    $byCategory = [];
    foreach ($assets as $asset) {
        $category = (string)($asset['category'] ?? 'forex');
        if (!isset($byCategory[$category])) {
            $byCategory[$category] = [];
        }
        $byCategory[$category][] = $asset;
    }
    $modals = [];
    foreach ($byCategory as $category => $categoryAssets) {
        $modals[] = [
            'id' => $category,
            'title' => ucfirst($category),
            'type' => 'market_list',
            'items' => array_map(static function(array $asset): array {
                return [
                    'symbol' => (string)$asset['symbol'],
                    'name' => (string)$asset['display_name'],
                    'category' => (string)$asset['category'],
                    'payout' => (float)$asset['payout_percent'],
                    'volatility' => (float)$asset['volatility'],
                ];
            }, $categoryAssets),
        ];
    }

    return [
        'fpjsDomain' => null,
        'liveBalance' => number_format($liveBalance, 8, '.', ''),
        'demoBalance' => number_format($demoBalance, 8, '.', ''),
        'tournamentsBalances' => [],
        'bonus' => [],
        'possibleBonus' => null,
        'activePromocodes' => [],
        'activeTournamentProfiles' => [],
        'tournamentsMeta' => ['current' => 0, 'coming' => 0, 'completed' => 0],
        'isDemo' => $accountType === 'demo' ? 1 : 0,
        'isDemoProfile' => $accountType === 'demo',
        'tournamentId' => 0,
        'id' => (string)$user['id'],
        'token' => 'LOCAL_PLATFORM_SESSION',
        'csrf' => csrf_token(),
        'nickname' => (string)($user['nickname'] ?: $user['name']),
        'email' => (string)$user['email'],
        'avatar' => $user['avatar_filename'] ? '/en/avatar/?id=' . (int)$user['id'] : '/profile/images/avatar.svg',
        'country' => (string)($user['country'] ?: 'IN'),
        'countryName' => original_country_name((string)($user['country'] ?: 'IN')),
        'countryIp' => (string)($user['country'] ?: 'IN'),
        'url' => '/en/',
        'lang' => 'en',
        'langs' => [
            'en' => ['code' => 'gb', 'url' => '/en/trade/', 'name' => 'English', 'active' => true],
        ],
        'tzlist' => [
            ['value' => '19800', 'time' => '+05:30', 'label' => '(UTC+05:30)'],
        ],
        'timeOffset' => '19800',
        'currencyCode' => (string)$user['currency'],
        'currencySymbol' => original_currency_symbol((string)$user['currency']),
        'currencies' => [(string)$user['currency']],
        'profileLevel' => 'standart',
        'rawRates' => [(string)$user['currency'] => 1],
        'exchangeRates' => [(string)$user['currency'] => 1],
        'countUnreadedMessages' => 0,
        'countUnreadedNotifications' => 0,
        'hasProfileNotifications' => false,
        'minDealAmount' => $minimum,
        'maxDealAmount' => $maximum,
        'minDealAmountUsd' => $minimum,
        'maxDealAmountUsd' => $maximum,
        'minDepositAmount' => (float)setting('minimum_demo_deposit', 10),
        'recommendedDepositAmount' => 100,
        'defaultError' => 'An error occurred',
        'requiredFieldError' => 'This field is required',
        'levelsList' => [0, 5000, 10000],
        'onlyFastAllowed' => false,
        'tgLink' => '',
        'background' => ['file' => null, 'opacity' => null],
        'maxDemoBalance' => 100000000,
        'isHideTradingUI' => false,
        'isHideTournaments' => true,
        'countNonActivatedPromocodes' => 0,
        'is_email_verified' => !empty($user['email_verified_at']),
        'is_verified_profile' => (string)($user['verification_status'] ?? 'unverified') === 'verified',
        'day_limit' => 0,
        'day_balance' => 0,
        'isAiTradingEnabled' => false,
        'showAccountBlockedModal' => false,
        'activeTournaments' => ['data' => []],
        'currencyFlags' => [(string)$user['currency'] => ['INR' => 'in', 'GBP' => 'gb', 'EUR' => 'eu'][$user['currency']] ?? 'us'],
        'modals' => $modals,
    ];
}

function original_currency_symbol(string $currency): string
{
    $symbols = ['USD' => '$', 'INR' => '₹', 'EUR' => '€', 'GBP' => '£'];
    return $symbols[$currency] ?? $currency;
}

function original_country_name(string $code): string
{
    $countries = [
        'IN' => 'India',
        'AE' => 'United Arab Emirates',
        'GB' => 'United Kingdom',
        'US' => 'United States',
    ];
    return $countries[strtoupper($code)] ?? 'Other';
}

function render_original_trade_route(array $user): void
{
    if ((bool)setting('maintenance_mode', false) && !is_admin($user)) {
        render_document_start('Maintenance', 'maintenance-page black', ['css/maintenance.css']);
        ?>
        <main class="maintenance-shell"><a href="/en/"><?= brand_logo(false) ?></a><section><span class="maintenance-icon"><?= icon('settings', 42) ?></span><span class="eyebrow">Scheduled maintenance</span><h1>We’ll be back shortly</h1><p>The trading room is temporarily unavailable while the administrator completes an update. Your account and recorded activity remain stored.</p><div><a class="button button--ghost" href="/en/settings/">Account</a><a class="button button--primary" href="/en/support/">Contact support</a></div></section></main>
        <?php
        render_document_end();
        return;
    }
    render_original_trade_page($user);
}

function render_original_trade_page(array $user): void
{
    $assets = market_assets(true);
    if (!$assets) {
        throw new RuntimeException('No active markets are configured.');
    }

    $minimum = (float)setting('minimum_trade_amount', 1);
    $maximum = (float)setting('maximum_trade_amount', 1000);
    $durations = allowed_trade_durations();
    $defaultDuration = in_array(60, $durations, true) ? 60 : (int)$durations[0];
    $version = rawurlencode((string)app_config('version'));
    $accountType = trade_account_type();
    $platformName = trim((string)setting('platform_name', app_config('name'))) ?: '55AK Trade';
    $customLogoFilename = trim((string)setting('logo_filename', ''));
    $liveBalance = (float)$user['demo_balance'];
    $demoBalance = (float)$user['demo_trade_balance'];
    $activeBalance = $accountType === 'demo' ? $demoBalance : $liveBalance;
    $bootstrapTime = (int)floor(microtime(true) * 1000);
    $bootstrapAssets = array_map(static fn (array $asset): array => market_asset_payload($asset, $bootstrapTime), $assets);
    $bootstrapAssetsJson = (string)json_encode($bootstrapAssets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $bootstrapAssetsEncoded = rtrim(strtr(base64_encode($bootstrapAssetsJson), '+/', '-_'), '=');
    $notificationStmt = db()->prepare("SELECT id,title,message,NULL image_filename,level FROM user_notifications WHERE is_active=1 AND (user_id IS NULL OR user_id=?) AND (starts_at IS NULL OR starts_at<=NOW()) AND (expires_at IS NULL OR expires_at>=NOW()) ORDER BY (user_id IS NOT NULL) DESC,id DESC LIMIT 1");
    $notificationStmt->execute([(int)$user['id']]);
    $announcement = $notificationStmt->fetch();
    if (!$announcement) {
        $announcement = db()->query("SELECT id,title,message,image_filename,level FROM announcements WHERE is_active=1 AND (starts_at IS NULL OR starts_at<=NOW()) AND (ends_at IS NULL OR ends_at>=NOW()) ORDER BY id DESC LIMIT 1")->fetch();
    }
    $meta = [
        'trade-user-id' => (string)$user['id'],
        'trade-user-name' => (string)$user['name'],
        'trade-user-nickname' => (string)($user['nickname'] ?: $user['name']),
        'trade-user-avatar' => $user['avatar_filename'] ? '/en/avatar/?id=' . (int)$user['id'] : '/profile/images/avatar.svg',
        'trade-user-email' => (string)$user['email'],
        'trade-user-balance' => number_format($activeBalance, 2, '.', ''),
        'trade-live-balance' => number_format($liveBalance, 2, '.', ''),
        'trade-demo-balance' => number_format($demoBalance, 2, '.', ''),
        'trade-account-type' => $accountType,
        'trade-user-currency' => (string)$user['currency'],
        'trade-user-country' => (string)($user['country'] ?: 'IN'),
        'trade-csrf' => csrf_token(),
        'trade-api-token' => trade_api_token($user),
        'trade-is-admin' => is_admin($user) ? '1' : '0',
        'trade-show-onboarding' => empty($user['onboarding_completed_at']) && ($user['role'] ?? 'user') === 'user' ? '1' : '0',
        'trade-min-amount' => number_format($minimum, 2, '.', ''),
        'trade-max-amount' => number_format($maximum, 2, '.', ''),
        'trade-allowed-durations' => implode(',', $durations),
        'trade-default-duration' => (string)$defaultDuration,
        'trade-chart-history-count' => (string)max(60, min(10000, (int)setting('chart_history_candles', 10000))),
        'trade-quote-poll-ms' => (string)max(250, min(2000, (int)setting('chart_quote_interval_ms', 650))),
        'trade-bootstrap-assets' => $bootstrapAssetsEncoded,
        'trade-server-time' => (string)$bootstrapTime,
        'trade-platform-name' => $platformName,
    ];
    if ($customLogoFilename !== '') {
        $meta['trade-custom-logo-url'] = site_logo_url();
    }
    if ($announcement) {
        $meta['trade-announcement-title'] = (string)$announcement['title'];
        $meta['trade-announcement-message'] = (string)$announcement['message'];
        $meta['trade-announcement-level'] = (string)$announcement['level'];
        if (!empty($announcement['image_filename'])) {
            $meta['trade-announcement-image'] = '/en/announcement-image/?id=' . (int)$announcement['id'];
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    ?><!doctype html>
<html lang="en" class="black">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="theme-color" content="#1c1f2d">
    <meta name="color-scheme" content="dark">
    <meta name="google" content="notranslate">
<?php foreach ($meta as $name => $content): ?>    <meta name="<?= e($name) ?>" content="<?= e($content) ?>">
<?php endforeach; ?>    <title><?= e($platformName) ?> — Trading platform</title>
    <link rel="icon" href="<?= e(site_logo_url()) ?>">
    <link rel="stylesheet" href="/trade-room/react/uikit.3cf3eedf78f32f7d0d9d.css">
    <link rel="stylesheet" href="/trade-room/react/app.1e382d4238188d8c0401.css">
    <link rel="stylesheet" href="/profile/css/custom.css">
    <link rel="stylesheet" href="/assets/css/original-trade.css?v=<?= $version ?>">
</head>
<body class="loading original-trade-page">
<noscript>You need to enable JavaScript to use 55AK Trade.</noscript>
<div id="root"></div>
<?php
$bridgeFile = dirname(__DIR__) . '/assets/js/original-trade-bridge.js';
$bridgeVer = file_exists($bridgeFile) ? (string)filemtime($bridgeFile) : $version;
?>
<script src="/assets/js/original-trade-bridge.js?v=<?= $bridgeVer ?>"></script>
<script src="/trade-room/react/runtime.fa9ef21ed989beb8b8b7.js"></script>
<script src="/trade-room/react/uikit.944983ce32773e612c15.js"></script>
<script src="/trade-room/react/vendor.ae7d8f8a004fd2feac4d.js"></script>
<script src="/trade-room/react/346.3bcb3c98900d5c44aedc.js"></script>
<script src="/trade-room/react/617.1c3acbb07452931d5651.js"></script>
<script src="/trade-room/react/708.530efc3bf733316adac2.js"></script>
<script src="/trade-room/react/app.514f7c2627cb588c0e57.js"></script>
</body>
</html><?php
}
