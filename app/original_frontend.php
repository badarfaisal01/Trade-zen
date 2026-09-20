<?php
declare(strict_types=1);

function render_original_auth_template(string $templatePath, string $error = '', string $email = '', string $success = '', string $referralCode = ''): void
{
    $html = (string)file_get_contents($templatePath);
    if ($html === '') {
        throw new RuntimeException('The supplied authentication frontend could not be loaded.');
    }

    // The supplied pages contain analytics, anti-bot and remote session scripts.
    // Authentication behavior is supplied by the local PHP application instead.
    $html = (string)preg_replace('~<script\b[^>]*>[\s\S]*?</script>~i', '', $html);
    $html = (string)preg_replace('~<link\b[^>]+(?:debug-bar|googletag|cloudflare)[^>]*>~i', '', $html);
    $html = (string)preg_replace('~\s+onsubmit="[^"]*"~i', '', $html);
    $html = (string)preg_replace('~<input\b[^>]*name="time_offset"[^>]*>~i', '', $html);
    $html = (string)preg_replace('~<video\b[^>]*>[\s\S]*?</video>~i', '', $html);
    $html = (string)preg_replace('~<video\b[^>]*>~i', '', $html);
    // Let the browser/password manager distinguish registration credentials
    // from the current login password without ever exposing stored hashes.
    $html = (string)preg_replace('~(<input\b(?=[^>]*\bid="password-input-registration")[^>]*\bautocomplete)="[^"]*"~i', '$1="new-password"', $html);
    $html = (string)preg_replace('~(<input\b(?=[^>]*\bid="password-input")[^>]*\bautocomplete)="[^"]*"~i', '$1="current-password"', $html);
    $html = (string)preg_replace('~(<input\b(?=[^>]*\bid="emailInput")[^>]*\bautocomplete)="[^"]*"~i', '$1="email"', $html);

    $html = (string)preg_replace('~https?://blog\.qxbroker\.com[^"\'<>\s]*~i', '/en/blog/', $html);
    $html = str_replace('https://qxbroker.com', '', $html);
    $html = str_replace('http://qxbroker.com', '', $html);
    $configuredName = trim((string)setting('platform_name', app_config('name')));
    $platformName = ($configuredName !== '' && stripos($configuredName, 'tradezen') === false && stripos($configuredName, '55ak') === false) ? $configuredName : 'Quotex';
    $safePlatformName = e($platformName);
    $authLogo = trim((string)setting('logo_filename', '')) !== '' ? site_logo_url() : '/assets/img/quotex-logo-white.svg';
    $html = (string)preg_replace('~(["\'])/site/img/quotex_logo-white(?:-mobile)?\.svg\1~i', '$1' . $authLogo . '$1', $html);
    $html = (string)preg_replace_callback(
        '~\b(href|src)="https?://[^"]+"~i',
        static function (array $match): string {
            return strtolower($match[1]) === 'src' ? 'src=""' : 'href="#"';
        },
        $html
    );
    $html = (string)preg_replace('~<link\b(?=[^>]*\brel="alternate")[^>]*>~i', '', $html);
    $html = (string)preg_replace('~<link\b(?=[^>]*\brel="(?:shortcut icon|icon|apple-touch-icon|mask-icon)")[^>]*>~i', '', $html);
    $html = (string)preg_replace('~(<link\b(?=[^>]*\brel="apple-touch-icon")[^>]*\bhref)="#"~i', '$1="' . e(site_logo_url()) . '"', $html);
    $html = (string)preg_replace('~(<link\b(?=[^>]*\brel="manifest")[^>]*\bhref)="#"~i', '$1="/manifest.webmanifest"', $html);

    // Keep the supplied navigation shape while routing every usable action locally.
    $html = (string)preg_replace('~href="/(?:[a-z]{2})/sign-in/?"~i', 'href="/en/sign-in/"', $html);
    $html = (string)preg_replace('~href="/(?:[a-z]{2})/sign-up/?"~i', 'href="/en/sign-up/"', $html);
    $html = (string)preg_replace('~href="/(?:[a-z]{2}/)?password-recovery/?"~i', 'href="/en/password-recovery/"', $html);
    $html = (string)preg_replace('~href="/(?:[a-z]{2}/)?about-us/?"~i', 'href="/en/about/"', $html);
    $html = (string)preg_replace('~href="/(?:[a-z]{2}/)?faq/?([^\"]*)"~i', 'href="/en/faq/$1"', $html);
    $html = (string)preg_replace('~href="/(?:[a-z]{2}/)?contacts/?"~i', 'href="/en/contact/"', $html);
    $html = (string)preg_replace('~href="/documents/(?:[a-z]{2}/)?Privacy_Policy[^\"]*"~i', 'href="/en/privacy/"', $html);
    $html = (string)preg_replace('~href="/documents/(?:[a-z]{2}/)?Risk_Disclosure[^\"]*"~i', 'href="/en/risk/"', $html);
    $html = (string)preg_replace('~href="/documents/(?:[a-z]{2}/)?[^\"]*"~i', 'href="/en/terms/"', $html);
    $html = (string)preg_replace('~href="/(?:[a-z]{2}/)?sign-up/fast/?"~i', 'href="/en/sign-up/"', $html);
    $html = (string)preg_replace('~href="/en/pwa/?"~i', 'href="/en/"', $html);
    $html = (string)preg_replace('~href="/(?!en/|assets/|site/|profile/|images/|fonts/|manifest\.webmanifest|.*\.apk)[^"]*"~i', 'href="#"', $html);
    $html = (string)preg_replace('~(<a\b[^>]*\bhref)="#"~i', '$1="/en/"', $html);
    $html = (string)preg_replace_callback('~<link\b[^>]*\brel="apple-touch-icon"[^>]*>~i', static function (array $match): string {
        return (string)preg_replace('~\bhref="[^"]*"~i', 'href="' . e(site_logo_url()) . '"', $match[0]);
    }, $html);
    $html = (string)preg_replace_callback('~<link\b[^>]*\brel="manifest"[^>]*>~i', static function (array $match): string {
        return (string)preg_replace('~\bhref="[^"]*"~i', 'href="/manifest.webmanifest"', $match[0]);
    }, $html);

    $replaceVisibleCopy = static function (string $copy) use ($safePlatformName): string {
        return str_ireplace(
            [
                'Quotex', 'QxBroker', 'QX Broker', 'Qx broker', 'quotexio',
                'start real trading', 'start real investments', 'real investments',
                'minimum deposit is only 10$', 'The minimum deposit is 10$',
                'withdrawal of funds', 'Service Agreement', 'Affiliate program',
                'Financial questions', 'Verification', 'Demo account', 'demo account',
                'demo credits', 'demo practice', 'practice simulator',
            ],
            [
                $safePlatformName, $safePlatformName, $safePlatformName, $safePlatformName, $safePlatformName,
                'open the trading platform', 'open the trading platform', 'platform access',
                'secure account access', 'Secure account access',
                'account withdrawals', 'Platform Terms', 'Platform partners',
                'Payment questions', 'Account help', 'Trading account', 'trading account',
                'account balance', 'platform access', 'trading platform',
            ],
            $copy
        );
    };
    $html = (string)preg_replace_callback(
        '~>([^<]+)<~s',
        static function (array $match) use ($replaceVisibleCopy): string {
            return '>' . $replaceVisibleCopy($match[1]) . '<';
        },
        $html
    );
    $html = (string)preg_replace_callback(
        '~\b(content|title|alt|aria-label|placeholder)="([^"]*)"~i',
        static function (array $match) use ($replaceVisibleCopy): string {
            return $match[1] . '="' . $replaceVisibleCopy($match[2]) . '"';
        },
        $html
    );

    $csrfField = '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
    $html = (string)preg_replace('~<input\b[^>]*name="_token"[^>]*>~i', $csrfField, $html);
    if (stripos($html, 'password-input-registration') !== false) {
        // Referral is a visible optional registration field. A link such as
        // ?ref=AK000001 prefills it, while users can also enter a shared code.
        $referralField = '<div class="modal-sign__input original-referral-field">'
            . '<label class="modal-sign__input-label" for="referralCodeInput">Referral code <small>(optional)</small></label>'
            . '<input type="text" name="ref" value="' . e($referralCode) . '" class="modal-sign__input-value" id="referralCodeInput" maxlength="32" pattern="[A-Za-z0-9_-]{3,32}" autocomplete="off" autocorrect="off" autocapitalize="characters" spellcheck="false" placeholder="Enter referral code">'
            . '</div>';
        $html = (string)preg_replace('~(<div\s+class="modal-sign__input\s*">\s*<label\s+class="modal-sign__input-label\s*">Password</label>)~i', $referralField . '$1', $html, 1);
    }
    $html = (string)preg_replace_callback(
        '~(<input\s+type="email"\s+name="email"\s+value=")[^"]*~i',
        static function (array $match) use ($email): string {
            return $match[1] . e($email);
        },
        $html
    );

    $countries = app_country_options('PK');
    $html = (string)preg_replace('~(<select\s+name="country-code"[^>]*>)[\s\S]*?(</select>)~i', '$1' . $countries . '$2', $html);
    $html = (string)preg_replace('~(<select\s+name="currency"[^>]*>)[\s\S]*?(</select>)~i', '$1<option value="USD" selected>USD · Account currency</option>$2', $html);

    $footer = '<div class="footer__container original-footer-note"><div class="footer__text"><p><strong>' . e($platformName) . ' account security</strong></p><p>Never share your password, OTP, UPI PIN, card PIN, private key, or wallet seed phrase. Payment requests are verified through the administrator workflow.</p><p>Operate this project only on your own domain and keep administrator and database credentials private.</p><p>Copyright © ' . date('Y') . ' ' . e($platformName) . '.</p></div></div></footer>';
    $html = (string)preg_replace('~<div class="footer__container">[\s\S]*?</footer>~i', $footer, $html);

    $notice = '<div class="original-demo-notice"><strong>' . e($platformName) . ' secure access</strong><span>Sign in only on your trusted platform domain.</span></div>';
    if ($success !== '') {
        $notice .= '<div class="original-auth-alert original-auth-alert--success">' . e($success) . '</div>';
    }
    if ($error !== '') {
        $notice .= '<div class="original-auth-alert">' . e($error) . '</div>';
    }
    $html = str_replace('<div class="modal-sign__block">', $notice . '<div class="modal-sign__block">', $html);

    $headAssets = '<link rel="icon" href="' . e(site_logo_url()) . '"><link rel="stylesheet" href="/assets/css/original-auth-bridge.css?v=' . rawurlencode((string)app_config('version')) . '&t=' . time() . '">';
    $html = str_replace('</head>', $headAssets . '</head>', $html);
    $html = str_replace('</body>', '<script src="/assets/js/original-auth.js" defer></script></body>', $html);
    $html = str_ireplace(['tradezen secure access', '55ak trade secure access'], 'Quotex secure access', $html);
    $html = str_ireplace(['tradezen account security', '55ak trade account security'], 'Quotex account security', $html);

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo $html;
}
