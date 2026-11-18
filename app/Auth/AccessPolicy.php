<?php

declare(strict_types=1);

namespace QuizMyClass\Auth;

use RuntimeException;

final class AccessPolicy
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @param array<string, mixed> $profile */
    public function assertAllowed(array $profile): void
    {
        $email = strtolower((string) ($profile['email'] ?? ''));
        $verified = $profile['email_verified'] ?? false;
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || $verified !== true) {
            throw new RuntimeException('Google must supply a verified email address.');
        }

        $allowedEmails = array_map(
            static fn (string $value): string => strtolower($value),
            $this->config['access']['allowed_test_emails'] ?? [],
        );
        if (in_array($email, $allowedEmails, true)) {
            return;
        }

        $domain = substr($email, strrpos($email, '@') + 1);
        $allowedDomains = array_map(
            static fn (string $value): string => strtolower(ltrim($value, '@.')),
            $this->config['access']['allowed_domains'] ?? [],
        );
        if (in_array($domain, $allowedDomains, true)) {
            return;
        }

        throw new RuntimeException('This Google account is not approved for this deployment.');
    }
}

