<?php

declare(strict_types=1);

namespace QuizMyClass\Support;

use RuntimeException;

final class Config
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $root): array
    {
        $defaultsFile = $root . '/config/defaults.php';
        if (!is_file($defaultsFile)) {
            throw new RuntimeException('Default configuration is missing.');
        }

        $config = require $defaultsFile;
        $localFile = getenv('QUIZMYCLASS_CONFIG_FILE') ?: $root . '/config/local.php';
        if (is_file($localFile)) {
            $local = require $localFile;
            if (!is_array($local)) {
                throw new RuntimeException('Local configuration must return an array.');
            }
            $config = self::merge($config, $local);
        }

        self::applyEnvironmentOverrides($config);
        self::validate($config);

        return $config;
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function merge(array $base, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
                $base[$key] = self::merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function applyEnvironmentOverrides(array &$config): void
    {
        $overrides = [
            'QUIZMYCLASS_ENVIRONMENT' => ['app', 'environment'],
            'QUIZMYCLASS_DEBUG' => ['app', 'debug'],
            'QUIZMYCLASS_SESSION_SECRET' => ['app', 'session_secret'],
            'QUIZMYCLASS_TIMEZONE' => ['app', 'timezone'],
            'QUIZMYCLASS_BASE_URL' => ['app', 'base_url'],
            'QUIZMYCLASS_DATABASE_PATH' => ['database', 'path'],
            'QUIZMYCLASS_GOOGLE_CLIENT_ID' => ['google', 'client_id'],
            'QUIZMYCLASS_GOOGLE_CLIENT_SECRET' => ['google', 'client_secret'],
            'QUIZMYCLASS_GOOGLE_REDIRECT_URI' => ['google', 'redirect_uri'],
        ];

        foreach ($overrides as $environmentName => [$section, $key]) {
            $value = getenv($environmentName);
            if ($value === false || $value === '') {
                continue;
            }
            $config[$section][$key] = $key === 'debug'
                ? filter_var($value, FILTER_VALIDATE_BOOLEAN)
                : $value;
        }
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function validate(array $config): void
    {
        $environment = $config['app']['environment'] ?? '';
        if (!in_array($environment, ['development', 'production', 'test'], true)) {
            throw new RuntimeException('Application environment must be development, production, or test.');
        }

        $secret = $config['app']['session_secret'] ?? '';
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('Run php scripts/install.php to create a local session secret.');
        }

        $timezone = $config['app']['timezone'] ?? 'UTC';
        try {
            new \DateTimeZone((string) $timezone);
        } catch (\Throwable $exception) {
            throw new RuntimeException('Configured timezone is invalid.', 0, $exception);
        }

        $databasePath = $config['database']['path'] ?? '';
        if (!is_string($databasePath) || $databasePath === '') {
            throw new RuntimeException('Database path must be configured.');
        }

        foreach ($config['access'] ?? [] as $name => $values) {
            if (!is_array($values)) {
                throw new RuntimeException('Access configuration ' . $name . ' must be a list.');
            }
            foreach ($values as $value) {
                if (!is_string($value) || $value === '') {
                    throw new RuntimeException('Access configuration entries must be non-empty strings.');
                }
            }
        }

        if ($environment === 'production') {
            $baseUrl = (string) ($config['app']['base_url'] ?? '');
            $redirectUri = (string) ($config['google']['redirect_uri'] ?? '');
            $clientId = (string) ($config['google']['client_id'] ?? '');
            $clientSecret = (string) ($config['google']['client_secret'] ?? '');
            $access = $config['access'] ?? [];
            if (strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https'
                || strtolower((string) parse_url($redirectUri, PHP_URL_SCHEME)) !== 'https'
                || self::origin($baseUrl) !== self::origin($redirectUri)
            ) {
                throw new RuntimeException(
                    'Production base URL and Google callback must use the same HTTPS origin.'
                );
            }
            if ($clientId === '' || $clientSecret === '') {
                throw new RuntimeException('Google credentials are required in production.');
            }
            if (($access['allowed_domains'] ?? []) === []
                && ($access['allowed_test_emails'] ?? []) === []
            ) {
                throw new RuntimeException('At least one account allowlist is required in production.');
            }
        }
    }

    private static function origin(string $url): string
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return strtolower($parts['scheme'] . '://' . $parts['host'] . $port);
    }
}

