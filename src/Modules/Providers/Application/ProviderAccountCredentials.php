<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application;

/**
 * The one path that decrypts a provider account's secret — used only by provider
 * adapters (Phases 21–23) when calling the provider. Kept separate from
 * {@see ProviderAccountDirectory} so the decrypt capability isn't handed out
 * with every read.
 */
interface ProviderAccountCredentials
{
    /**
     * @return string|null the plaintext secret, or null if the account does not exist
     *
     * @throws \Gomrok\Shared\Application\SecretDecryptionFailed
     */
    public function secretFor(int $accountId): ?string;

    /**
     * The plaintext signing secret for an account's active endpoint of a kind,
     * or null if there is none / it has no secret.
     *
     * @throws \Gomrok\Shared\Application\SecretDecryptionFailed
     */
    public function endpointSigningSecret(int $accountId, string $endpointKind): ?string;
}
