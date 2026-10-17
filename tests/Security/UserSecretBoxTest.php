<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\UserSecretBox;
use PHPUnit\Framework\TestCase;

final class UserSecretBoxTest extends TestCase
{
    public function testARoundTripReturnsThePlaintextAndNeverStoresIt(): void
    {
        $box = new UserSecretBox(self::key());
        $stored = $box->encrypt('sk-secret-value');

        self::assertStringStartsWith('v1:', $stored);
        self::assertStringNotContainsString('sk-secret-value', $stored);
        self::assertNotSame($stored, $box->encrypt('sk-secret-value'), 'a fresh nonce each time');
        self::assertSame('sk-secret-value', $box->decrypt($stored));
    }

    public function testATamperedValueFailsRatherThanDecryptingToGarbage(): void
    {
        $box = new UserSecretBox(self::key());
        $stored = $box->encrypt('sk-secret-value');
        $raw = base64_decode(substr($stored, 3), true) ?: '';
        $raw[\strlen($raw) - 1] = \chr(\ord($raw[\strlen($raw) - 1]) ^ 1);

        $this->expectException(\RuntimeException::class);
        $box->decrypt('v1:' . base64_encode($raw));
    }

    public function testAValueFromAnotherKeyDoesNotDecrypt(): void
    {
        $stored = (new UserSecretBox(self::key()))->encrypt('sk-secret-value');

        $this->expectException(\RuntimeException::class);
        (new UserSecretBox(self::key()))->decrypt($stored);
    }

    public function testWithoutAUsableKeyTheBoxSaysSoAndRefusesToEncrypt(): void
    {
        foreach (['', 'not-base64!', base64_encode('too short')] as $bad) {
            self::assertFalse((new UserSecretBox($bad))->isConfigured());
        }

        $this->expectException(\LogicException::class);
        (new UserSecretBox(''))->encrypt('x');
    }

    private static function key(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }
}
