<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

if (is_post()) {
    verify_csrf();
    auth_logout(false);
} else {
    // The local bridge uses POST+CSRF. This guarded GET fallback keeps captured
    // legacy logout links working without allowing a cross-site page to log a
    // user out silently.
    $fetchSite = strtolower((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''));
    $refererHost = strtolower((string)(parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_HOST) ?: ''));
    $requestHost = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '');
    $sameOriginReferer = $refererHost !== '' && $requestHost !== '' && $refererHost === $requestHost;
    if (in_array($fetchSite, ['', 'same-origin', 'same-site', 'none'], true) || $sameOriginReferer) {
        auth_logout(false);
    }
}

header('Location: /en/sign-in/');
exit;
