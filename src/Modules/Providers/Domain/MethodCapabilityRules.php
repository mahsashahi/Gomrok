<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * Payment-method-level narrowing of a provider type's declaration.
 *
 * **Phase 8 placeholder.** The known nuances (from `CLAUDE.md` / `Knowledge.md`)
 * are hard-coded here so the capability resolver and its tests can account for
 * method. A real `provider_type_method_capabilities` table replaces this in
 * Phase 9 or Phase 12, whichever first needs persisted per-method data
 * (`provider_type_method_capabilities`).
 */
final class MethodCapabilityRules
{
    public static function constraintFor(string $providerTypeCode, PaymentMethod $method): MethodConstraint
    {
        return match ($providerTypeCode . ':' . $method->value) {
            // Mollie's alternative methods are one-off only — no recurring, no subscription.
            'mollie:paypal', 'mollie:ideal', 'mollie:bancontact' => new MethodConstraint(
                excludedCapabilities: [
                    Capability::SubscriptionCancel,
                    Capability::SubscriptionPause,
                    Capability::SubscriptionResume,
                    Capability::CustomerPortal,
                ],
                excludedPurchaseTypes: [
                    PurchaseType::RecurringPayment,
                    PurchaseType::AutoCharge,
                    PurchaseType::Subscription,
                ],
            ),
            default => MethodConstraint::none(),
        };
    }
}
