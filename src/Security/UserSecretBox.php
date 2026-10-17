<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Encrypts secrets users give us to hold — today, their own AI provider key — with
 * libsodium's secretbox (XSalsa20-Poly1305: authenticated, so a tampered value fails
 * to decrypt rather than decrypting to garbage). The 32-byte key comes from
 * `USER_SECRETS_KEY` (base64), kept apart from `APP_SECRET` so rotating one never
 * breaks the other. Stored form: `v1:` + base64(nonce ‖ ciphertext); the prefix leaves
 * room for a key rotation later.
 *
 * Never log a plaintext, and never put one in an exception message.
 */
final class UserSecretBox
{
    private const PREFIX = 'v1:';

    private readonly ?string $key;

    /** @param string $userSecretsKey base64 of 32 random bytes; '' leaves the box unconfigured */
    public function __construct(string $userSecretsKey)
    {
        $raw = $userSecretsKey === '' ? false : base64_decode($userSecretsKey, true);
        $this->key = \is_string($raw) && \strlen($raw) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? $raw : null;
    }

    /** Whether USER_SECRETS_KEY is set to a usable key; without it nothing can be stored. */
    public function isConfigured(): bool
    {
        return $this->key !== null;
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode($nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key()));
    }

    /** @throws \RuntimeException when the value is malformed, tampered with, or from another key */
    public function decrypt(string $stored): string
    {
        $raw = str_starts_with($stored, self::PREFIX)
            ? base64_decode(substr($stored, \strlen(self::PREFIX)), true)
            : false;
        if (!\is_string($raw) || \strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Stored secret is not in a readable format.');
        }

        $plaintext = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key(),
        );
        if ($plaintext === false) {
            throw new \RuntimeException('Stored secret could not be decrypted (wrong key or altered value).');
        }

        return $plaintext;
    }

    private function key(): string
    {
        if ($this->key === null) {
            throw new \LogicException('USER_SECRETS_KEY is not set to a base64-encoded 32-byte key.');
        }

        return $this->key;
    }
}
