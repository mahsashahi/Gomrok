<?php

declare(strict_types=1);

namespace Gomrok\Shared\Application;

/**
 * Symmetric encryption for secrets that must be stored (provider secret keys,
 * webhook signing secrets). Behind a port so a Vault / KMS implementation can
 * replace the default libsodium one without a schema or caller change
 * (`PhaseDecisions.md` Phase 9 Q1).
 */
interface SecretCipher
{
    /**
     * @return string opaque, storable ciphertext (safe for a `TEXT` column)
     */
    public function encrypt(#[\SensitiveParameter] string $plaintext): string;

    /**
     * @throws SecretDecryptionFailed on a tampered ciphertext or the wrong key
     */
    public function decrypt(string $ciphertext): string;
}
