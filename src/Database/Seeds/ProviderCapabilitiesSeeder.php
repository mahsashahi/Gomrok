<?php

declare(strict_types=1);

namespace Gomrok\Database\Seeds;

use Gomrok\Modules\Providers\Domain\Capability;
use Phinx\Seed\AbstractSeed;

/**
 * Seeds `provider_capabilities` from the `Capability` enum (the source of
 * truth). Idempotent (upsert on `code`).
 */
final class ProviderCapabilitiesSeeder extends AbstractSeed
{
    public function run(): void
    {
        $pdo = $this->getAdapter()->getConnection();

        $statement = $pdo->prepare(
            'INSERT INTO provider_capabilities (code, label, description, capability_group)
             VALUES (:code, :label, :description, :capability_group)
             ON DUPLICATE KEY UPDATE
                 label = VALUES(label),
                 description = VALUES(description),
                 capability_group = VALUES(capability_group)',
        );

        foreach (Capability::cases() as $capability) {
            $statement->execute([
                'code' => $capability->value,
                'label' => $capability->label(),
                'description' => self::DESCRIPTIONS[$capability->value],
                'capability_group' => $capability->group()->value,
            ]);
        }
    }

    /** @var array<string, string> */
    private const DESCRIPTIONS = [
        'hosted_checkout' => 'Provider hosts the full checkout page.',
        'redirect_payment' => 'Customer is redirected to the provider to pay, then back.',
        'embedded_payment_form' => 'Payment fields embedded in the client page via the provider SDK.',
        'card_tokenization' => 'Card details can be tokenised for reuse.',
        'authorization' => 'Funds can be authorised without immediate capture.',
        'capture' => 'A prior authorisation can be captured.',
        'cancel' => 'A pending / authorised payment can be cancelled.',
        'refund' => 'A captured payment can be refunded in full.',
        'partial_refund' => 'A captured payment can be partially refunded.',
        'subscription_cancel' => 'An active subscription can be cancelled.',
        'subscription_pause' => 'An active subscription can be paused.',
        'subscription_resume' => 'A paused subscription can be resumed.',
        'customer_portal' => 'Provider-hosted portal for the customer to manage payment methods / subscriptions.',
        'billing_portal' => 'Provider-hosted billing / invoice history portal.',
        'invoice' => 'Provider can issue invoices.',
        'three_d_secure' => 'Supports 3-D Secure authentication.',
        'webhook' => 'Sends asynchronous webhook events.',
        'return_url' => 'Redirects the customer to a return URL after payment.',
        'manual_status_polling' => 'Status must be polled from the provider API (no reliable webhook).',
    ];
}
