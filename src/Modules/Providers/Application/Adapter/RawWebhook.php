<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * An inbound webhook exactly as received, before verification or parsing —
 * the `Webhooks` module (Phase 25) is expected to store the raw body first,
 * then hand it here. `webhookSigningSecret` is the account endpoint's
 * decrypted secret (via `ProviderAccountCredentials::endpointSigningSecret()`),
 * resolved by the caller — the adapter never touches encrypted storage.
 */
final readonly class RawWebhook
{
    public function __construct(
        public string $payload,
        public string $signatureHeader,
        public string $webhookSigningSecret,
    ) {
    }
}
