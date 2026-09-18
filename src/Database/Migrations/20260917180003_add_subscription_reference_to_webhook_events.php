<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `webhook_events.subscription_reference` (Phase 29 Q2) — a renewal-charge
 * webhook's own `provider_reference` is a brand-new payment id that has never
 * been seen before, so it can never resolve via the existing
 * `provider_reference` → `gateway_references` lookup the way a first-payment
 * webhook does. Adapters now additionally surface the *subscription* id a
 * charge belongs to (`ParsedWebhookEvent::$subscriptionReference`), stored
 * here so `ProcessWebhookEventHandler` can resolve it against a
 * `GatewayReferenceType::Subscription` row instead and route the charge to
 * `RecordSubscriptionPaymentHandler` — the same fix serves Stripe's own
 * billing-engine webhooks and Mollie's real Subscription resource (Q2)
 * identically.
 */
final class AddSubscriptionReferenceToWebhookEvents extends AbstractMigration
{
    public function up(): void
    {
        $this->table('webhook_events')
            ->addColumn('subscription_reference', 'string', ['limit' => 191, 'null' => true, 'after' => 'provider_reference'])
            ->update();
    }

    public function down(): void
    {
        $this->table('webhook_events')
            ->removeColumn('subscription_reference')
            ->update();
    }
}
