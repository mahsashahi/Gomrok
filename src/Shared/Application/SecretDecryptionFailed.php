<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application;

use RuntimeException;

/**
 * A stored ciphertext could not be decrypted — tampered, corrupt, or encrypted
 * with a different key. Treated as an infrastructure fault (500 path), not a
 * `DomainError`.
 */
final class SecretDecryptionFailed extends RuntimeException
{
    public function __construct(string $context = 'secret')
    {
        parent::__construct("Failed to decrypt {$context}.");
    }
}
