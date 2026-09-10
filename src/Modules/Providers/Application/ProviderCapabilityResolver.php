<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application;

use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Domain\MethodCapabilityRules;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclaration;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclarations;
use Gomrok\Modules\Providers\Domain\PurchaseType;

/**
 * Resolves what a provider type can do, optionally for a specific payment
 * method. Phase 8 layer: type declaration (`provider_type_*` tables) minus
 * {@see MethodCapabilityRules} exclusions. Later phases wrap this with account
 * (Phase 9) and client/country (Phase 10) narrowing.
 *
 * Unknown provider type → `null` from {@see resolve()}, `false` from the boolean
 * helpers — never a silent "yes".
 */
final readonly class ProviderCapabilityResolver
{
    public function __construct(private ProviderTypeDeclarations $declarations)
    {
    }

    public function resolve(string $providerTypeCode, ?PaymentMethod $method = null): ?ResolvedProviderCapabilities
    {
        $declaration = $this->declarations->findByCode($providerTypeCode);
        if ($declaration === null) {
            return null;
        }

        return $this->applyMethod($declaration, $method);
    }

    public function supports(string $providerTypeCode, Capability $capability, ?PaymentMethod $method = null): bool
    {
        return $this->resolve($providerTypeCode, $method)?->supports($capability) ?? false;
    }

    public function supportsPurchaseType(string $providerTypeCode, PurchaseType $purchaseType, ?PaymentMethod $method = null): bool
    {
        return $this->resolve($providerTypeCode, $method)?->supportsPurchaseType($purchaseType) ?? false;
    }

    private function applyMethod(ProviderTypeDeclaration $declaration, ?PaymentMethod $method): ResolvedProviderCapabilities
    {
        $capabilities = $declaration->capabilities;
        $purchaseTypes = $declaration->purchaseTypes();

        if ($method !== null) {
            $constraint = MethodCapabilityRules::constraintFor($declaration->providerTypeCode, $method);

            if (!$constraint->isEmpty()) {
                $capabilities = $capabilities->without(...$constraint->excludedCapabilities);

                $excluded = array_map(
                    static fn (PurchaseType $pt): string => $pt->value,
                    $constraint->excludedPurchaseTypes,
                );
                $purchaseTypes = array_values(array_filter(
                    $purchaseTypes,
                    static fn (PurchaseType $pt): bool => !\in_array($pt->value, $excluded, true),
                ));
            }
        }

        return new ResolvedProviderCapabilities(
            $declaration->providerTypeCode,
            $method,
            $purchaseTypes,
            $capabilities,
        );
    }
}
