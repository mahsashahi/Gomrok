<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * An inbound webhook exactly as received, before verification or parsing —
 * the `Webhooks` module (Phase 25) is expected to store the raw body first,
 * then hand it here. `webhookSigningSecret` is the account endpoint's
 * decrypted secret (via `ProviderAccountCredentials::endpointSigningSecret()`),
 * resolved by the caller — the adapter never touches encrypted storage.
 *
 * `headers` (Phase 22 Q5) is an additive, generic bag of raw request headers
 * for providers whose verification needs more than one signature header and
 * one secret — PayPal's verify-webhook-signature call needs five headers
 * (transmission id/time, cert URL, auth algo, signature). Stripe keeps using
 * only `signatureHeader`/`webhookSigningSecret`; Mollie uses neither field —
 * it has no webhook signature at all, so its adapter verifies by re-fetching
 * the resource named in the payload.
 */
final readonly class RawWebhook
{
    /**
     * @param array<string, string> $headers raw request headers, keyed by header name
     */
    public function __construct(
        public string $payload,
        public string $signatureHeader,
        public string $webhookSigningSecret,
        public array $headers = [],
    ) {
    }
}
