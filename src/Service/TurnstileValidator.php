<?php

declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cloudflare Turnstile CAPTCHA verification for the public invitation-request
 * form. Configured via TURNSTILE_SITEKEY / TURNSTILE_SECRET in .env.local.
 *
 * Mirrors the app's optional-integration pattern (cf. Twilio): when no secret is
 * configured the check is a no-op that passes, so local/dev works without keys.
 */
class TurnstileValidator
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(TURNSTILE_SITEKEY)%')]
        private readonly string $siteKey = '',
        #[Autowire('%env(TURNSTILE_SECRET)%')]
        private readonly string $secret = '',
    ) {
    }

    /** Public site key for the widget; empty string when CAPTCHA is disabled. */
    public function siteKey(): string
    {
        return $this->siteKey;
    }

    /** Whether a secret is configured (and therefore verification is enforced). */
    public function isEnabled(): bool
    {
        return $this->secret !== '' && $this->siteKey !== '';
    }

    /**
     * Verify a Turnstile response token. Returns true when CAPTCHA is disabled
     * (no secret), and false on a missing token or a failed/erroring check.
     */
    public function verify(?string $token, ?string $ip = null): bool
    {
        if (!$this->isEnabled()) {
            return true;
        }
        if ($token === null || $token === '') {
            return false;
        }

        try {
            $response = $this->httpClient->request('POST', self::VERIFY_URL, [
                'body' => array_filter([
                    'secret' => $this->secret,
                    'response' => $token,
                    'remoteip' => $ip,
                ]),
            ]);

            return (bool) ($response->toArray(false)['success'] ?? false);
        } catch (\Throwable $e) {
            $this->logger->warning('Turnstile verification failed: {error}', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
