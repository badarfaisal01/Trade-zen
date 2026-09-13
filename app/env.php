<?php
declare(strict_types=1);

/**
 * Clean Architecture Environment & Configuration Manager
 * Handles loading, caching, parsing, and typed retrieval of all environment variables and configuration.
 */

if (!function_exists('load_dotenv')) {
    /**
     * Parse and load a .env file into PHP environment ($_ENV, $_SERVER, getenv, and static cache)
     *
     * @param string|null $path Optional path to .env file
     * @return array Loaded environment key-value pairs
     */
    function load_dotenv(?string $path = null): array
    {
        static $loadedData = null;
        if ($loadedData !== null && $path === null) {
            return $loadedData;
        }

        $envPath = $path ?? (defined('BASE_PATH') ? BASE_PATH . '/.env' : dirname(__DIR__) . '/.env');
        $loadedData = [];

        if (!file_exists($envPath) || !is_readable($envPath)) {
            return $loadedData;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return $loadedData;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Strip enclosing quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            } else {
                // Strip trailing comments on unquoted values
                $commentPos = strpos($value, '#');
                if ($commentPos !== false) {
                    $value = trim(substr($value, 0, $commentPos));
                }
            }

            // Export to all PHP environment interfaces
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            $loadedData[$key] = $value;
        }

        return $loadedData;
    }
}

if (!function_exists('env')) {
    /**
     * Retrieve a typed environment variable with default fallback
     *
     * @param string $key Environment variable name
     * @param mixed $default Fallback value if key is not defined or empty
     * @return mixed
     */
    function env(string $key, $default = null)
    {
        // 1. Check $_ENV
        $value = $_ENV[$key] ?? null;

        // 2. Check $_SERVER
        if ($value === null && isset($_SERVER[$key])) {
            $value = $_SERVER[$key];
        }

        // 3. Check getenv
        if ($value === null) {
            $envVal = getenv($key);
            if ($envVal !== false) {
                $value = $envVal;
            }
        }

        // 4. Check loaded .env file cache if not yet found
        if ($value === null) {
            $loaded = load_dotenv();
            if (isset($loaded[$key])) {
                $value = $loaded[$key];
            }
        }

        if ($value === null || $value === '') {
            return $default;
        }

        if (!is_string($value)) {
            return $value;
        }

        $trimmed = trim($value);
        $lowered = strtolower($trimmed);

        // Type conversion for standard boolean and null strings
        switch ($lowered) {
            case 'true':
            case '(true)':
                return true;
            case 'false':
            case '(false)':
                return false;
            case 'empty':
            case '(empty)':
                return '';
            case 'null':
            case '(null)':
                return null;
        }

        return $value;
    }
}

if (!function_exists('env_has')) {
    /**
     * Check if an environment variable exists and is not null
     */
    function env_has(string $key): bool
    {
        return env($key) !== null;
    }
}

if (!function_exists('env_all')) {
    /**
     * Get all loaded environment variables as an array
     */
    function env_all(): array
    {
        return load_dotenv();
    }
}

if (!function_exists('config')) {
    /**
     * Retrieve configuration using dot-notation (e.g., config('app.name'), config('database.host'))
     *
     * @param string $key Dot-notated configuration key
     * @param mixed $default Fallback value
     * @return mixed
     */
    function config(string $key, $default = null)
    {
        static $configCache = [];

        $parts = explode('.', $key, 2);
        $file = $parts[0];
        $item = $parts[1] ?? null;

        if (!isset($configCache[$file])) {
            $path = (defined('BASE_PATH') ? BASE_PATH : dirname(__DIR__)) . "/config/{$file}.php";
            if (file_exists($path)) {
                $configCache[$file] = require $path;
            } else {
                $configCache[$file] = [];
            }
        }

        if ($item === null) {
            return $configCache[$file] ?? $default;
        }

        return $configCache[$file][$item] ?? $default;
    }
}
