<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure;

use Gomrok\Modules\Providers\Application\Adapter\PaymentProviderPort;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\Adapter\UnsupportedProviderType;
use Gomrok\Modules\Providers\Application\ProviderAccountCredentials;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclarations;
use Gomrok\Modules\Providers\Infrastructure\Adapter\Stripe\StripeAdapter;
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
            default => throw new UnsupportedProviderType("No adapter implemented for provider type '{$account->providerTypeCode}' yet."),
        };
    }
}
