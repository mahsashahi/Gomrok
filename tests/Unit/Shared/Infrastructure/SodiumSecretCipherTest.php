<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Infrastructure;

use Gomrok\Shared\Application\SecretDecryptionFailed;
use Gomrok\Shared\Infrastructure\Crypto\SodiumSecretCipher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SodiumSecretCipherTest extends TestCase
{
    private const KEY_B64 = 'KioqKioqKioqKioqKioqKioqKioqKioqKioqKioqKio=';

    #[Test]
    public function roundTrips(): void
    {
        $cipher = SodiumSecretCipher::fromBase64Key(self::KEY_B64);

        $plaintext = 'sk_live_51ABCdefGHI';
        $encrypted = $cipher->encrypt($plaintext);

        self::assertNotSame($plaintext, $encrypted);
        self::assertStringNotContainsString($plaintext, $encrypted);
        self::assertSame($plaintext, $cipher->decrypt($encrypted));
    }

    #[Test]
    public function eachEncryptionIsUnique(): void
    {
        $cipher = SodiumSecretCipher::fromBase64Key(self::KEY_B64);

        self::assertNotSame($cipher->encrypt('same'), $cipher->encrypt('same'));
    }

    #[Test]
    public function rejectsATamperedCiphertext(): void
    {
        $cipher = SodiumSecretCipher::fromBase64Key(self::KEY_B64);
        $encrypted = $cipher->encrypt('secret');

        $raw = base64_decode($encrypted, true);
        self::assertIsString($raw);
        $raw[\strlen($raw) - 1] = $raw[\strlen($raw) - 1] === 'A' ? 'B' : 'A';

        $this->expectException(SecretDecryptionFailed::class);
        $cipher->decrypt(base64_encode($raw));
    }

    #[Test]
    public function rejectsADifferentKey(): void
    {
        $encrypted = SodiumSecretCipher::fromBase64Key(self::KEY_B64)->encrypt('secret');
        $other = new SodiumSecretCipher(random_bytes(\SODIUM_CRYPTO_SECRETBOX_KEYBYTES));

        $this->expectException(SecretDecryptionFailed::class);
        $other->decrypt($encrypted);
    }

    #[Test]
    public function requiresAKey(): void
    {
        $this->expectException(RuntimeException::class);
        SodiumSecretCipher::fromBase64Key(null);
    }

    #[Test]
    public function requiresACorrectLengthKey(): void
    {
        $this->expectException(RuntimeException::class);
        new SodiumSecretCipher('too-short');
    }
}
