<?php
declare(strict_types=1);

function app_config(?string $key = null)
{
    global $app;
    return $key === null ? $app : ($app[$key] ?? null);
}

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

function asset_url(string $path): string
{
    return url('assets/' . ltrim($path, '/')) . '?v=' . rawurlencode((string)app_config('version'));
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

function request_expects_json(): bool
{
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    return strpos($accept, 'application/json') !== false || strpos($contentType, 'application/json') !== false || strpos((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/api/') !== false;
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function request_data(): array
{
    $contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
    if (strpos($contentType, 'application/json') !== false) {
        $decoded = json_decode((string)file_get_contents('php://input'), true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function is_post(): bool
{
    return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST';
}

function money($amount, string $currency = 'USD'): string
{
    $symbols = ['USD' => '$', 'INR' => '₹', 'EUR' => '€', 'GBP' => '£'];
    $prefix = $symbols[$currency] ?? ($currency . ' ');
    return $prefix . number_format((float)$amount, 2, '.', ',');
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function pull_flashes(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return is_array($items) ? $items : [];
}

function setting(string $key, $default = null)
{
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        try {
            foreach (db()->query('SELECT setting_key, setting_value, setting_type FROM site_settings')->fetchAll() as $row) {
                $value = $row['setting_value'];
                if ($row['setting_type'] === 'number') {
                    $value = (float)$value;
                } elseif ($row['setting_type'] === 'boolean') {
                    $value = in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
                } elseif ($row['setting_type'] === 'json') {
                    $decoded = json_decode((string)$value, true);
                    $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
                }
                $settings[$row['setting_key']] = $value;
            }
        } catch (Throwable $ignored) {
            $settings = [];
        }
    }

    return array_key_exists($key, $settings) ? $settings[$key] : $default;
}

function reset_setting_cache(): void
{
    // The next request starts a new PHP process/request and rebuilds the static cache.
}

function pagination(int $total, int $page, int $perPage): array
{
    $pages = max(1, (int)ceil($total / max(1, $perPage)));
    $page = max(1, min($page, $pages));
    return ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'pages' => $pages, 'offset' => ($page - 1) * $perPage];
}

function public_id(string $prefix = 'TR'): string
{
    return strtoupper($prefix . date('ymd') . substr(bin2hex(random_bytes(6)), 0, 10));
}

function safe_next_path(?string $path, string $fallback): string
{
    $path = (string)$path;
    if ($path === '' || $path[0] !== '/' || strpos($path, '//') === 0 || strpos($path, '\\') !== false || preg_match('/[\x00-\x1F\x7F]/', $path)) {
        return $fallback;
    }
    return $path;
}
