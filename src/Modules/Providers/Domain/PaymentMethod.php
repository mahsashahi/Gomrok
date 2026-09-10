<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * Payment methods Gomrok knows about (Phase 8 Q4 — enum only; there is no
 * `payment_methods` table yet). Trimmed to what the currently-planned providers
 * use; extend when a provider adapter needs more.
 *
 * Method-level capability nuance ("Mollie card can do recurring, Mollie PayPal
 * cannot") is captured for now by {@see MethodCapabilityRules}, a placeholder
 * that a real `provider_type_method_capabilities` table replaces in Phase 9/12.
 */
enum PaymentMethod: string
{
    case Card = 'card';
    case PayPal = 'paypal';
    case Ideal = 'ideal';
    case Bancontact = 'bancontact';
    case SepaDirectDebit = 'sepa_direct_debit';
    case BankHostedCard = 'bank_hosted_card';
}
