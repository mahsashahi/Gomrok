<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Crypto;

use Gomrok\Shared\Application\SecretCipher;
use Gomrok\Shared\Application\SecretDecryptionFailed;
use RuntimeException;
use SensitiveParameter;

/**
 * {@see SecretCipher} using libsodium's authenticated encryption
 * (`crypto_secretbox`, XSalsa20-Poly1305). Storage form: base64 of
 * `nonce (24B) || ciphertext+tag`. Key is 32 raw bytes from
 * `APP_ENCRYPTION_KEY` (base64) — see {@see fromBase64Key()}.
 */
final readonly class SodiumSecretCipher implements SecretCipher
{
    /**
     * @param string $key 32 raw bytes
     */
    public function __construct(#[SensitiveParameter] private string $key)
    {
        if (\strlen($key) !== \SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(\sprintf(
                'Encryption key must be %d bytes, got %d.',
                \SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                \strlen($key),
            ));
        }
    }

    /**
     * Build from the base64 `APP_ENCRYPTION_KEY` value.
     */
    public static function fromBase64Key(#[SensitiveParameter] ?string $base64Key): self
    {
        if ($base64Key === null || $base64Key === '') {
            throw new RuntimeException(
                'APP_ENCRYPTION_KEY is not set. Generate one: php -r "echo base64_encode(random_bytes(32));"',
            );
        }

        $raw = base64_decode($base64Key, true);
        if ($raw === false) {
            throw new RuntimeException('APP_ENCRYPTION_KEY is not valid base64.');
        }

        return new self($raw);
    }

    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $ciphertext);
    }

    public function decrypt(string $ciphertext): string
    {
        $raw = base64_decode($ciphertext, true);
        $minimum = \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES;

        if ($raw === false || \strlen($raw) < $minimum) {
            throw new SecretDecryptionFailed();
        }

        $nonce = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $payload = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plaintext = sodium_crypto_secretbox_open($payload, $nonce, $this->key);
        if ($plaintext === false) {
            throw new SecretDecryptionFailed();
        }

        return $plaintext;
    }
}
