<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * Phase 26: a third nullable parent on `gateway_references`, alongside
 * `checkout_attempt_id` (Phase 24 Q1) and `payment_id`. Exactly one of the
 * three is set per row — app-enforced by which named constructor is used
 * (`GatewayReference::forCheckoutAttempt()` / `::forPayment()` /
 * `::forSubscription()`), not a DB constraint, the same pattern the first
 * additive parent column already established. A provider's real Subscription
 * resource id (Stripe: the `sub_...` id) is recorded under
 * `GatewayReferenceType::Subscription` once `ReconcileCheckoutStatusHandler`
 * creates the `Subscription` row — the initial `CheckoutSession` reference at
 * checkout-creation time still points at the checkout attempt, same as a
 * one-time payment.
 */
final class AddSubscriptionIdToGatewayReferences extends AbstractMigration
{
    public function up(): void
    {
        $this->table('gateway_references')
            ->addColumn('subscription_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'payment_id'])
            ->addIndex(['subscription_id'], ['name' => 'idx_gateway_references_subscription'])
            ->addForeignKey('subscription_id', 'subscriptions', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_gateway_references_subscription'])
            ->update();
    }

    public function down(): void
    {
        $this->table('gateway_references')
            ->dropForeignKey('subscription_id')
            ->removeIndexByName('idx_gateway_references_subscription')
            ->removeColumn('subscription_id')
            ->update();
    }
}
