<?php

declare(strict_types=1);

namespace QuizMyClass\Auth;

use JsonException;
use RuntimeException;

final class GoogleClient
{
    private const AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const SCOPE = 'openid email profile';

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function isConfigured(): bool
    {
        $google = $this->config['google'] ?? [];
        return isset($google['client_id'], $google['client_secret'], $google['redirect_uri'])
            && is_string($google['client_id'])
            && is_string($google['client_secret'])
            && is_string($google['redirect_uri'])
            && $google['client_id'] !== ''
            && $google['client_secret'] !== ''
            && filter_var($google['redirect_uri'], FILTER_VALIDATE_URL) !== false;
    }

    public static function verifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function state(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function authorizeUrl(string $state, string $verifier): string
    {
        $this->requireConfiguration();
        $query = http_build_query([
            'client_id' => $this->config['google']['client_id'],
            'redirect_uri' => $this->config['google']['redirect_uri'],
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'state' => $state,
            'code_challenge' => rtrim(
                strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'),
                '=',
            ),
            'code_challenge_method' => 'S256',
            'prompt' => 'select_account',
            'access_type' => 'online',
        ], '', '&', PHP_QUERY_RFC3986);

        return self::AUTHORIZE_URL . '?' . $query;
    }

    /**
     * @return array<string, mixed>
     */
    public function authenticate(string $code, string $verifier): array
    {
        $this->requireConfiguration();
        if (strlen($code) > 4096 || strlen($verifier) !== 43) {
            throw new RuntimeException('Google sign-in response is invalid.');
        }

        $token = $this->request(self::TOKEN_URL, [
            'client_id' => $this->config['google']['client_id'],
            'client_secret' => $this->config['google']['client_secret'],
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $this->config['google']['redirect_uri'],
            'grant_type' => 'authorization_code',
        ]);

        $accessToken = $token['access_token'] ?? '';
        if (!is_string($accessToken) || $accessToken === '' || strlen($accessToken) > 8192) {
            throw new RuntimeException('Google did not return an access token.');
        }

        $profile = $this->request(
            self::USERINFO_URL,
            headers: ['Authorization: Bearer ' . $accessToken],
        );
        unset($token, $accessToken);

        if (($profile['email_verified'] ?? null) !== true
            && ($profile['email_verified'] ?? null) !== 'true'
            && ($profile['email_verified'] ?? null) !== 1
        ) {
            throw new RuntimeException('Google did not verify this email address.');
        }
        $profile['email_verified'] = true;

        return $profile;
    }

    /**
     * @param array<string, string>|null $form
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function request(
        string $url,
        ?array $form = null,
        array $headers = [],
    ): array {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new RuntimeException('Unable to initialize the Google request.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => [...$headers, 'Accept: application/json'],
        ];
        if ($form !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form, '', '&', PHP_QUERY_RFC3986);
            $options[CURLOPT_HTTPHEADER] = [
                ...$headers,
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ];
        }
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_error($handle);
        curl_close($handle);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Unable to contact Google for sign-in.');
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Google declined the sign-in request.');
        }

        try {
            $decoded = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Google returned an invalid sign-in response.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Google returned an invalid sign-in response.');
        }
        return $decoded;
    }

    private function requireConfiguration(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException(
                'Google sign-in is not configured for this deployment.'
            );
        }
    }
}

