<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * The stored form of a provider secret — opaque ciphertext plus a display hint.
 * The plaintext never lives here; the application layer encrypts (via
 * `Shared\Application\SecretCipher`) and hands one of these to the aggregate.
 */
final readonly class EncryptedSecret
{
    public function __construct(
        public string $ciphertext,
        public string $lastFour,
    ) {
    }

    /**
     * @param string $ciphertext already-encrypted value
     */
    public static function fromParts(string $ciphertext, string $plaintextForHint): self
    {
        return new self($ciphertext, substr($plaintextForHint, -4));
    }

    public function masked(): string
    {
        return '••••' . $this->lastFour;
    }
}
