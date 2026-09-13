<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure;

use Gomrok\Modules\Providers\Application\Adapter\PaymentProviderPort;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\Adapter\UnsupportedProviderType;
use Gomrok\Modules\Providers\Application\ProviderAccountCredentials;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountSummary;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclarations;
use Gomrok\Modules\Providers\Infrastructure\Adapter\Mollie\MollieAdapter;
use Gomrok\Modules\Providers\Infrastructure\Adapter\PayPal\PayPalAdapter;
use Gomrok\Modules\Providers\Infrastructure\Adapter\Stripe\StripeAdapter;
use GuzzleHttp\Client as GuzzleClient;
use Mollie\Api\MollieApiClient;
use RuntimeException;
use Stripe\StripeClient;

/**
 * The one place that knows "provider type code X gets adapter class Y"
 * (Phase 21 Q4) — grows a `match` arm per provider type as Phases 22–23 add
 * Mollie/PayPal/Ziraat. Builds a fresh, stateless adapter instance per call,
 * configured with that one account's decrypted secret.
 */
final readonly class DefaultProviderAdapterFactory implements ProviderAdapterFactory
{
    public function __construct(
        private ProviderAccountDirectory $accounts,
        private ProviderAccountCredentials $credentials,
        private ProviderTypeDeclarations $declarations,
    ) {
    }

    public function for(int $providerAccountId): PaymentProviderPort
    {
        $account = $this->accounts->findById($providerAccountId);
        if ($account === null) {
            throw new RuntimeException("Provider account {$providerAccountId} was not found.");
        }

        $secret = $this->credentials->secretFor($providerAccountId);
        if ($secret === null) {
            throw new RuntimeException("Provider account {$providerAccountId} was not found.");
        }

        return match ($account->providerTypeCode) {
            'stripe' => new StripeAdapter(new StripeClient($secret), $this->declarations),
            'mollie' => new MollieAdapter((new MollieApiClient())->setApiKey($secret), $this->declarations),
            'paypal' => $this->buildPayPalAdapter($account, $secret),
            default => throw new UnsupportedProviderType("No adapter implemented for provider type '{$account->providerTypeCode}' yet."),
        };
    }

    /**
     * PayPal's secret (Phase 22 Q4) is a JSON-encoded `{client_id,
     * client_secret}` pair packed into the single `secret_ciphertext`
     * column, not one opaque string like Stripe/Mollie's API keys. The base
     * URI switches on the account's own `mode` (sandbox vs live) — PayPal,
     * unlike Stripe/Mollie, uses a different hostname per environment rather
     * than encoding it in the key itself.
     */
    private function buildPayPalAdapter(ProviderAccountSummary $account, string $secret): PayPalAdapter
    {
        $credentials = json_decode($secret, true);
        if (
            !\is_array($credentials)
            || !\is_string($credentials['client_id'] ?? null)
            || !\is_string($credentials['client_secret'] ?? null)
        ) {
            throw new RuntimeException("Provider account {$account->id} does not hold valid PayPal credentials (expected JSON {client_id, client_secret}).");
        }

        $baseUri = $account->mode === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';

        return new PayPalAdapter(new GuzzleClient(), $credentials['client_id'], $credentials['client_secret'], $baseUri, $this->declarations);
    }
}
