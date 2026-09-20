<?php
declare(strict_types=1);

function icon(string $name, int $size = 20, string $class = ''): string
{
    $paths = [
        'chart' => '<path d="M4 19V9m5 10V5m5 14v-7m5 7V3"/><path d="M2 21h20"/>',
        'wallet' => '<path d="M3 6.5A2.5 2.5 0 0 1 5.5 4H19a2 2 0 0 1 2 2v13H5a2 2 0 0 1-2-2V6.5Z"/><path d="M3 8h15a3 3 0 0 1 3 3v4h-5a3 3 0 0 1 0-6h5"/><circle cx="16" cy="12" r=".7" fill="currentColor" stroke="none"/>',
        'history' => '<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5M12 7v5l3 2"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'logout' => '<path d="M10 17l5-5-5-5m5 5H3"/><path d="M14 3h5a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'minus' => '<path d="M5 12h14"/>',
        'arrow-up' => '<path d="m6 15 6-6 6 6"/>',
        'arrow-down' => '<path d="m6 9 6 6 6-6"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'x' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
        'shield' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-5"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06-2.83 2.83-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6 1.7 1.7 0 0 0-.4 1.1V21h-4v-.09A1.7 1.7 0 0 0 8.6 19.4a1.7 1.7 0 0 0-1.88.34l-.06.06-2.83-2.83.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.5-1H3v-4h.09A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.34-1.88l-.06-.06 2.83-2.83.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-1.5V3h4v.09A1.7 1.7 0 0 0 15 4.6a1.7 1.7 0 0 0 1.88-.34l.06-.06 2.83 2.83-.06.06A1.7 1.7 0 0 0 19.4 9a1.7 1.7 0 0 0 1.5 1h.1v4h-.09a1.7 1.7 0 0 0-1.51 1Z"/>',
        'activity' => '<path d="M3 12h4l2-7 4 14 2-7h6"/>',
        'request' => '<path d="M6 2h9l4 4v16H6z"/><path d="M14 2v5h5M9 13h6M9 17h6"/>',
        'asset' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M8 10c0-1.5 1.5-2.5 4-2.5s4 1 4 2.5-1.5 2-4 2-4 .5-4 2 1.5 2.5 4 2.5 4-1 4-2.5"/>',
        'audit' => '<path d="M9 11h6M9 15h4"/><path d="M6 3h12v18H6z"/><path d="M9 3V1h6v2"/>',
        'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6M12 7h.01"/>',
        'eye' => '<path d="M2 12s4-7 10-7 10 7 10 7-4 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'support' => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"/><path d="M9.5 9a2.5 2.5 0 1 1 4.2 1.8c-.9.7-1.7 1.1-1.7 2.2M12 16h.01"/>',
        'coupon' => '<path d="M3 9a3 3 0 0 0 0 6v4h18v-4a3 3 0 0 0 0-6V5H3z"/><path d="M13 5v2M13 10v2M13 15v4"/>',
        'payment' => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20M6 15h4"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
        'arrow-right' => '<path d="m9 18 6-6-6-6"/>',
        'camera' => '<path d="M14.5 4 16 6h3a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h3l1.5-2h5Z"/><circle cx="12" cy="13" r="3.5"/>',
        'globe' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"/><path d="M10 21h4"/>',
        'user-plus' => '<path d="M15 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8" cy="7" r="4"/><path d="M19 8v6M16 11h6"/>',
        'key' => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3M14 9l3 3"/>',
        'trash' => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 15H6L5 6M10 11v6M14 11v6"/>',
        'sparkles' => '<path d="m12 3 1.2 3.8L17 8l-3.8 1.2L12 13l-1.2-3.8L7 8l3.8-1.2L12 3ZM19 14l.7 2.3L22 17l-2.3.7L19 20l-.7-2.3L16 17l2.3-.7L19 14ZM5 13l.8 2.2L8 16l-2.2.8L5 19l-.8-2.2L2 16l2.2-.8L5 13Z"/>',
        'sliders' => '<path d="M4 21V14M4 10V3M12 21V12M12 8V3M20 21V16M20 12V3"/><path d="M1 14h6M9 8h6M17 16h6"/>',
        'percent' => '<line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'trend-up' => '<polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/>',
        'trend-down' => '<polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/>',
        'pause' => '<rect x="6" y="4" width="4" height="16"/><rect x="14" y="4" width="4" height="16"/>',
        'play' => '<polygon points="5 3 19 12 5 21 5 3"/>',
        'lock' => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    ];
    $body = $paths[$name] ?? $paths['info'];
    return '<svg class="icon ' . e($class) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

function trade_sprite_icon(string $name, int $size = 24, string $class = ''): string
{
    if (!preg_match('/^[a-z0-9_-]+$/', $name)) {
        $name = 'information';
    }
    $reference = '/profile/images/spritemap.svg#icon-' . $name;
    return '<svg class="qx-trade-icon ' . e($class) . '" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" aria-hidden="true"><use href="' . e($reference) . '" xlink:href="' . e($reference) . '"></use></svg>';
}

function payment_method_asset(array $method): ?string
{
    $key = strtolower(trim((string)($method['icon_key'] ?? '')));
    $name = strtolower(trim((string)($method['name'] ?? '')));
    $network = strtolower(trim((string)($method['network'] ?? '')));
    $haystack = $key . ' ' . $name . ' ' . $network;
    $asset = null;

    if (strpos($haystack, 'phonepe') !== false) {
        $asset = 'phonepe.svg';
    } elseif (strpos($haystack, 'paytm') !== false) {
        $asset = 'paytm.svg';
    } elseif (strpos($haystack, 'binance') !== false) {
        $asset = 'binance.svg';
    } elseif (strpos($haystack, 'upi') !== false) {
        $asset = 'upi.svg';
    } elseif (strpos($haystack, 'usdt') !== false && strpos($haystack, 'trc') !== false) {
        $asset = 'usdt_trc20.svg';
    } elseif (strpos($haystack, 'usdt') !== false && strpos($haystack, 'bep') !== false) {
        $asset = 'usdt_bep20.svg';
    } elseif (strpos($haystack, 'usdt') !== false && strpos($haystack, 'polygon') !== false) {
        $asset = 'usdt_polygon.svg';
    } elseif (strpos($haystack, 'usdt') !== false) {
        $asset = 'usdt_erc20.svg';
    } elseif (strpos($haystack, 'usdc') !== false && strpos($haystack, 'polygon') !== false) {
        $asset = 'crypto-usdc-polygon.svg';
    } elseif (strpos($haystack, 'usdc') !== false && strpos($haystack, 'eth') !== false) {
        $asset = 'usdc-eth.svg';
    } elseif (strpos($haystack, 'usdc') !== false) {
        $asset = 'usdc.svg';
    } elseif (strpos($haystack, 'bitcoin cash') !== false || preg_match('/\bbch\b/', $haystack)) {
        $asset = 'crypto-bch.svg';
    } elseif (strpos($haystack, 'bitcoin') !== false || preg_match('/\bbtc\b/', $haystack)) {
        $asset = 'crypto-btc.svg';
    } elseif (strpos($haystack, 'ethereum') !== false || preg_match('/\beth\b/', $haystack)) {
        $asset = 'crypto-eth.svg';
    } elseif (strpos($haystack, 'litecoin') !== false || preg_match('/\bltc\b/', $haystack)) {
        $asset = 'crypto-ltc.svg';
    } elseif (strpos($haystack, 'doge') !== false) {
        $asset = 'crypto-doge.svg';
    } elseif (strpos($haystack, 'dash') !== false) {
        $asset = 'crypto-dash.svg';
    } elseif (strpos($haystack, 'shiba') !== false) {
        $asset = 'crypto-shiba_erc20.svg';
    } elseif (strpos($haystack, 'tron') !== false || preg_match('/\btrx\b/', $haystack)) {
        $asset = 'crypto-tron.svg';
    } elseif (preg_match('/\b(?:xrp|xpr)\b/', $haystack)) {
        $asset = 'crypto-xpr.svg';
    } elseif (strpos($haystack, 'polygon') !== false || preg_match('/\bmatic\b/', $haystack)) {
        $asset = 'polygon.svg';
    } elseif (preg_match('/\bdai\b/', $haystack)) {
        $asset = 'dai.svg';
    } elseif (strpos($haystack, 'kucoin') !== false) {
        $asset = 'kucoin.svg';
    } elseif (preg_match('/\bton\b/', $haystack)) {
        $asset = 'ton.svg';
    }

    return $asset === null ? null : asset_url('img/payments/' . $asset);
}

function payment_method_mark(array $method, string $class = 'method-logo'): string
{
    $key = strtolower((string)($method['icon_key'] ?? 'wallet'));
    $category = strtolower((string)($method['category'] ?? 'other'));
    $classes = $class . ' method-logo--' . preg_replace('/[^a-z0-9_-]+/', '', $category) . ' method-logo--key-' . preg_replace('/[^a-z0-9_-]+/', '', $key);
    $asset = payment_method_asset($method);
    if ($asset !== null) {
        $content = '<img src="' . e($asset) . '" alt="" width="44" height="44" loading="lazy">';
    } elseif ($key === 'binance') {
        $content = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m12 3 3 3-3 3-3-3 3-3Zm-5 5 3 3-3 3-3-3 3-3Zm10 0 3 3-3 3-3-3 3-3Zm-5 5 3 3-3 3-3-3 3-3Zm0-5 3 3-3 3-3-3 3-3Z" fill="currentColor"/></svg>';
    } elseif ($key === 'usdt') {
        $content = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="10" fill="currentColor"/><path d="M7 7h10M12 7v10M8.5 10.5c2 .8 5 .8 7 0" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round"/></svg>';
    } elseif ($key === 'phonepe') {
        $content = '<b aria-hidden="true">Pe</b>';
    } elseif ($key === 'paytm') {
        $content = '<b aria-hidden="true">Pay</b>';
    } elseif ($key === 'upi') {
        $content = '<b aria-hidden="true">UPI</b>';
    } elseif ($key === 'bank') {
        $content = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m3 9 9-5 9 5M5 10v7m5-7v7m4-7v7m5-7v7M3 20h18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    } else {
        $content = '<b aria-hidden="true">' . e(strtoupper(substr((string)($method['name'] ?? 'Pay'), 0, 2))) . '</b>';
    }
    return '<span class="' . e($classes) . '">' . $content . '</span>';
}

function site_logo_url(): string
{
    $filename = trim((string)setting('logo_filename', ''));
    return $filename !== '' ? '/en/site-logo/?v=' . rawurlencode($filename) : asset_url('img/quotex-logo-white.svg');
}

function brand_logo(bool $compact = false): string
{
    $name = (string)setting('platform_name', app_config('name'));
    $customLogo = trim((string)setting('logo_filename', ''));
    if ($customLogo !== '') {
        $mark = '<img src="' . e(site_logo_url()) . '" alt="" width="25" height="25">';
        return '<span class="brand"><span class="brand-mark" aria-hidden="true">' . $mark . '</span><span class="brand-word">' . e($name) . '</span></span>';
    }
    return '<span class="brand" style="display:inline-flex;align-items:center;gap:8px;"><img src="/assets/img/quotex-logo-white.svg" alt="Quotex" height="32" style="height:32px;width:auto;display:block;"></span>';
}

function render_document_start(string $title, string $bodyClass = '', array $styles = [], bool $indexable = false): void
{
    $fullTitle = e($title . ' · ' . setting('platform_name', app_config('name')));
    $description = e(setting('platform_tagline', app_config('tagline')));
    $csrf = e(csrf_token());
    $styleTags = '';
    foreach ($styles as $style) {
        $href = strpos($style, '/') === 0 ? $style : asset_url($style);
        $styleTags .= '<link rel="stylesheet" href="' . e($href) . '">';
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="theme-color" content="#111522"><meta name="color-scheme" content="dark"><meta name="csrf-token" content="' . $csrf . '"><meta name="description" content="' . $description . '"><meta name="robots" content="' . ($indexable ? 'index,follow' : 'noindex,nofollow') . '"><title>' . $fullTitle . '</title><link rel="icon" href="' . e(site_logo_url()) . '"><link rel="manifest" href="/manifest.webmanifest"><link rel="stylesheet" href="' . e(asset_url('css/base.css')) . '">' . $styleTags . '</head><body class="' . e($bodyClass) . '">';
}

function render_flashes(): void
{
    $flashes = pull_flashes();
    if (!$flashes) {
        return;
    }
    echo '<div class="flash-stack">';
    foreach ($flashes as $flash) {
        echo '<div class="flash flash--' . e($flash['type']) . '">' . e($flash['message']) . '</div>';
    }
    echo '</div>';
}

function render_public_header(string $active = ''): void
{
    $user = current_user();
    echo '<header class="site-header"><a class="site-logo" href="/en/">' . brand_logo(false) . '</a><nav class="site-nav">';
    if ($user) {
        echo '<a class="' . ($active === 'trade' ? 'active' : '') . '" href="/en/trade/">Trade</a><a class="' . ($active === 'wallet' ? 'active' : '') . '" href="/en/balance/">Payments</a><a class="' . ($active === 'affiliate' ? 'active' : '') . '" href="/en/affiliate/">Affiliate</a><a class="' . ($active === 'analytics' ? 'active' : '') . '" href="/en/analytics/">Analytics</a><a class="' . ($active === 'history' ? 'active' : '') . '" href="/en/history/">History</a><a class="' . ($active === 'settings' ? 'active' : '') . '" href="/en/settings/">Account</a>';
        if (is_admin($user)) {
            echo '<a href="/admin/">Admin</a>';
        }
        echo '</nav><div class="header-account"><a class="header-balance" href="/en/balance/" aria-label="Open balance"><small><span class="header-live-dot"></span>Live</small><strong>' . e(money($user['demo_balance'], $user['currency'])) . '</strong></a><a class="header-deposit" href="/en/balance/?deposit=true">Deposit</a><a class="icon-button header-profile-link" href="/en/settings/" title="Open profile" aria-label="Open profile">' . icon('user') . '</a><form action="/en/logout/" method="post"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><button class="icon-button" title="Sign out" aria-label="Sign out">' . icon('logout') . '</button></form></div>';
    } else {
        echo '<a href="/en/about/">About</a><a href="/en/faq/">FAQ</a><a href="/en/blog/">Blog</a></nav><div class="header-actions"><a class="button button--ghost" href="/en/sign-in/">Log in</a><a class="button button--primary" href="/en/sign-up/">Create account</a></div>';
    }
    echo '</header>';
    if ($user) {
        echo '<nav class="mobile-nav" aria-label="Mobile navigation"><a class="' . ($active === 'trade' ? 'active' : '') . '" href="/en/trade/">' . icon('chart', 20) . '<span>Trade</span></a><a class="' . ($active === 'wallet' ? 'active' : '') . '" href="/en/balance/">' . icon('wallet', 20) . '<span>Payments</span></a><a class="' . ($active === 'affiliate' ? 'active' : '') . '" href="/en/affiliate/">' . icon('user-plus', 20) . '<span>Affiliate</span></a><a class="' . ($active === 'support' ? 'active' : '') . '" href="/en/support/">' . icon('support', 20) . '<span>Support</span></a><a class="' . ($active === 'settings' ? 'active' : '') . '" href="/en/settings/" data-nav-profile="1">' . icon('user', 20) . '<span>Profile</span></a></nav>';
    }
}

function render_cabinet_chrome_legacy(string $active = 'settings'): void
{
    $user = current_user();
    if (!$user) {
        render_public_header($active);
        return;
    }
    $items = [
        'trade' => ['/en/trade/', 'chart', 'Trade'],
        'wallet' => ['/en/balance/', 'wallet', 'Payments'],
        'analytics' => ['/en/analytics/', 'activity', 'Analytics'],
        'history' => ['/en/history/', 'history', 'History'],
        'affiliate' => ['/en/affiliate/', 'user-plus', 'Affiliate'],
        'support' => ['/en/support/', 'support', 'Support'],
        'settings' => ['/en/settings/', 'user', 'Account'],
    ];
    echo '<aside class="qx-cabinet-rail" aria-label="Account navigation"><a class="qx-cabinet-rail__logo" href="/en/trade/" aria-label="Trade home">' . brand_logo(true) . '</a><nav>';
    foreach ($items as $key => $item) {
        echo '<a class="' . ($active === $key ? 'active' : '') . '" href="' . e($item[0]) . '" title="' . e($item[2]) . '">' . icon($item[1], 21) . '<span>' . e($item[2]) . '</span></a>';
    }
    echo '</nav><form action="/en/logout/" method="post"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><button type="submit" title="Sign out" aria-label="Sign out">' . icon('logout', 21) . '</button></form></aside>';

    echo '<header class="qx-cabinet-topbar"><a class="qx-cabinet-topbar__brand" href="/en/trade/">' . brand_logo(false) . '<span>Web Trading Platform</span></a><div class="qx-cabinet-topbar__account"><a class="qx-cabinet-balance" href="/en/balance/"><small><i></i>Live</small><strong>' . e(money($user['demo_balance'], $user['currency'])) . '</strong></a><a class="qx-cabinet-deposit" href="/en/balance/?deposit=true">Deposit</a><a class="qx-cabinet-user" href="/en/settings/" aria-label="Open account">' . icon('user', 19) . '</a></div></header>';

    $mobileItems = array_intersect_key($items, array_flip(['trade', 'wallet', 'affiliate', 'support', 'settings']));
    echo '<nav class="qx-cabinet-mobile" aria-label="Mobile account navigation">';
    foreach ($mobileItems as $key => $item) {
        echo '<a class="' . ($active === $key ? 'active' : '') . '" href="' . e($item[0]) . '">' . icon($item[1], 19) . '<span>' . e($item[2]) . '</span></a>';
    }
    echo '</nav>';
}

function render_cabinet_chrome(string $active = 'settings'): void
{
    $user = current_user();
    if (!$user) {
        render_public_header($active);
        return;
    }

    $primary = [
        'trade' => ['/en/trade/', 'trade-sidebar', 'Trade'],
        'support' => ['/en/support/', 'help', 'Support'],
        'settings' => ['/en/settings/', 'profile', 'Account'],
        'affiliate' => ['/en/affiliate/', 'profile', 'Affiliate'],
    ];
    $moreActive = in_array($active, ['wallet', 'analytics', 'history'], true);
    $moreLinks = [
        ['/en/balance/?deposit=true', 'payment-mobile', 'Deposit'],
        ['/en/withdrawal/', 'wallet', 'Withdrawal'],
        ['/en/analytics/', 'analytics', 'Analytics'],
        ['/en/history/', 'mobile-trades', 'Trade history'],
        ['/en/settings/', 'profile', 'Settings'],
    ];

    echo '<aside class="qx-cabinet-rail" aria-label="Account navigation"><a class="qx-cabinet-rail__logo" href="/en/trade/" aria-label="Trade home">' . brand_logo(true) . '</a><nav class="qx-cabinet-primary">';
    foreach ($primary as $key => $item) {
        echo '<a class="' . ($active === $key ? 'active' : '') . '" href="' . e($item[0]) . '">' . trade_sprite_icon($item[1], 25) . '<span>' . e($item[2]) . '</span></a>';
    }
    echo '<details class="qx-cabinet-more ' . ($moreActive ? 'active' : '') . '"><summary>' . trade_sprite_icon('more-sidebar', 26) . '<span>More</span></summary><div class="qx-cabinet-more__menu">';
    foreach ($moreLinks as $item) {
        echo '<a href="' . e($item[0]) . '">' . trade_sprite_icon($item[1], 20) . '<span>' . e($item[2]) . '</span></a>';
    }
    echo '<form action="/en/logout/" method="post"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><button type="submit">' . trade_sprite_icon('logout', 20) . '<span>Sign out</span></button></form></div></details></nav></aside>';

    echo '<header class="qx-cabinet-topbar"><a class="qx-cabinet-topbar__brand" href="/en/trade/">' . brand_logo(false) . '<span>Web Trading Platform</span></a><div class="qx-cabinet-topbar__account"><a class="qx-cabinet-balance" href="/en/balance/"><small><i></i>Live</small><strong>' . e(money($user['demo_balance'], $user['currency'])) . '</strong></a><a class="qx-cabinet-deposit" href="/en/balance/?deposit=true">Deposit</a><a class="qx-cabinet-user" href="/en/settings/" aria-label="Open account">' . trade_sprite_icon('profile', 20) . '</a></div></header>';

    echo '<nav class="qx-cabinet-mobile" aria-label="Mobile account navigation">';
    foreach ($primary as $key => $item) {
        echo '<a class="' . ($active === $key ? 'active' : '') . '" href="' . e($item[0]) . '">' . trade_sprite_icon($item[1], 22) . '<span>' . e($item[2]) . '</span></a>';
    }
    echo '<details class="qx-cabinet-more ' . ($moreActive ? 'active' : '') . '"><summary>' . trade_sprite_icon('more-sidebar', 22) . '<span>More</span></summary><div class="qx-cabinet-more__menu">';
    foreach ($moreLinks as $item) {
        echo '<a href="' . e($item[0]) . '">' . trade_sprite_icon($item[1], 19) . '<span>' . e($item[2]) . '</span></a>';
    }
    echo '</div></details></nav>';
}

function render_account_switcher(string $active = 'profile'): void
{
    $labels = [
        'profile' => 'My account',
        'market' => 'Market',
        'deposit' => 'Deposit',
        'withdrawal' => 'Withdrawal',
        'activity' => 'Account activity',
        'affiliate' => 'Affiliate',
        'support' => 'Support',
    ];
    $icons = ['profile' => 'user', 'market' => 'coupon', 'deposit' => 'plus', 'withdrawal' => 'minus', 'activity' => 'activity', 'affiliate' => 'user-plus', 'support' => 'support'];
    $links = [
        'profile' => '/en/settings/',
        'market' => '/en/market/',
        'deposit' => '/en/balance/?deposit=true',
        'withdrawal' => '/en/withdrawal/',
        'activity' => '/en/history/',
        'affiliate' => '/en/affiliate/',
        'support' => '/en/support/',
    ];
    if (!isset($labels[$active])) {
        $active = 'profile';
    }
    echo '<section class="account-switcher"><button class="account-switcher__button" type="button" data-account-menu-toggle aria-expanded="false"><span><small>My account</small><strong>' . e($labels[$active]) . '</strong></span>' . icon('arrow-down', 18) . '</button><nav class="account-switcher__menu" data-account-menu hidden>';
    foreach ($labels as $key => $label) {
        echo '<a class="' . ($key === $active ? 'active' : '') . '" href="' . e($links[$key]) . '">' . icon($icons[$key], 18) . '<span>' . e($label) . '</span>' . ($key === $active ? icon('check', 16) : '') . '</a>';
    }
    echo '</nav></section>';
}

function render_public_footer(): void
{
    echo '<footer class="site-footer"><div>' . brand_logo(false) . '<p>' . e(setting('platform_tagline', app_config('tagline'))) . '</p></div><div class="footer-note"><strong>Useful links</strong><span><a href="/en/about/">About</a> · <a href="/en/faq/">FAQ</a> · <a href="/en/blog/">Blog</a> · <a href="/en/terms/">Terms</a> · <a href="/en/privacy/">Privacy</a></span></div><div>© ' . date('Y') . ' ' . e(setting('platform_name', app_config('name'))) . '</div></footer>';
}

function render_document_end(array $scripts = []): void
{
    foreach ($scripts as $script) {
        $src = strpos($script, '/') === 0 ? $script : asset_url($script);
        echo '<script src="' . e($src) . '" defer></script>';
    }
    echo '</body></html>';
}

function render_admin_start(string $title, string $active, array $admin): void
{
    $pendingAdminNotifications = 0;
    try {
        $pendingAdminNotifications = (int)db()->query("SELECT (SELECT COUNT(*) FROM fund_requests WHERE status='pending') + (SELECT COUNT(*) FROM support_tickets WHERE status='open')")->fetchColumn();
    } catch (Throwable $ignored) {
        $pendingAdminNotifications = 0;
    }
    render_document_start($title, 'admin-shell', ['css/admin.css']);
    echo '<aside class="admin-sidebar"><a class="admin-logo" href="/admin/">' . brand_logo(false) . '<span>Control Center</span></a><nav>';
    $links = [
        'dashboard' => ['Dashboard', 'dashboard'],
        'users' => ['Users', 'users'],
        'trades' => ['Trades', 'activity'],
        'requests' => ['Fund requests', 'request'],
        'finance' => ['Finance', 'wallet'],
        'payments' => ['Payment methods', 'payment'],
        'coupons' => ['Gift & coupons', 'coupon'],
        'support' => ['Support desk', 'support'],
        'assets' => ['Trading control', 'asset'],
        'trading' => ['Trading Center', 'sliders'],
        'market_api' => ['Market APIs', 'api'],
        'agents' => ['Agents & referrals', 'users'],
        'notifications' => ['Notifications', 'bell'],
        'branding' => ['Branding', 'image'],
        'admins' => ['Administrators', 'shield'],
        'settings' => ['Settings', 'settings'],
        'audit' => ['Audit log', 'audit'],
    ];
    $capabilityByLink = [
        'users' => 'manage_users',
        'payments' => 'manage_payments',
        'coupons' => 'manage_promos',
        'assets' => 'manage_markets',
        'trading' => 'manage_markets',
        'market_api' => 'manage_markets',
        'admins' => 'manage_admins',
        'agents' => 'manage_agents',
        'notifications' => 'manage_notifications',
        'branding' => 'manage_branding',
        'settings' => 'manage_settings',
    ];
    foreach ($links as $key => $link) {
        if (isset($capabilityByLink[$key]) && !admin_can($admin, $capabilityByLink[$key])) {
            continue;
        }
        $badge = in_array($key, ['requests','support'], true) && $pendingAdminNotifications > 0 ? '<b class="admin-nav-dot"></b>' : '';
        echo '<a class="' . ($active === $key ? 'active' : '') . '" href="/admin/?page=' . e($key) . '">' . icon($link[1]) . '<span>' . e($link[0]) . '</span>' . $badge . '</a>';
    }
    echo '</nav><div class="admin-sidebar-foot"><div class="admin-user"><span class="avatar">' . e(strtoupper(substr($admin['name'], 0, 1))) . '</span><div><strong>' . e($admin['name']) . '</strong><small>' . e(ucwords(str_replace('_', ' ', $admin['role']))) . '</small></div></div><form action="/en/logout/" method="post"><input type="hidden" name="_csrf" value="' . e(csrf_token()) . '"><button class="sidebar-logout">' . icon('logout') . ' Sign out</button></form></div></aside><main class="admin-main"><header class="admin-topbar"><button class="icon-button admin-menu-toggle" type="button" aria-label="Toggle menu">' . icon('menu') . '</button><div><span class="eyebrow">' . e(setting('platform_name', app_config('name'))) . ' Administration</span><h1>' . e($title) . '</h1></div><div class="admin-topbar-actions"><a class="admin-notification-button" href="/admin/?page=requests" title="Pending requests and support">' . icon('bell', 19) . ($pendingAdminNotifications > 0 ? '<b>' . min(99, $pendingAdminNotifications) . '</b>' : '') . '</a><a class="button button--ghost" href="/en/trade/">View platform</a></div></header>';
    render_flashes();
}

function render_admin_end(): void
{
    echo '</main>';
    render_document_end(['js/admin.js']);
}
