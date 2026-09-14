<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * Phase 24 Q1: a provider checkout-session reference (Stripe session id,
 * Mollie payment id, PayPal order id) is created before any `payments` row
 * exists — at `checkout_attempts.status = provider_checkout_created`, well
 * before the attempt reaches `confirmed` (`Payment::create()`'s precondition,
 * Phase 20 Q1). `gateway_references` gains a second nullable parent column so
 * a row can link to whichever of the two exists when it's written; the
 * existing `payment_id` behavior is unchanged.
 */
final class AddCheckoutAttemptIdToGatewayReferences extends AbstractMigration
{
    public function up(): void
    {
        $this->table('gateway_references')
            ->addColumn('checkout_attempt_id', 'integer', ['signed' => false, 'null' => true, 'after' => 'reference_value'])
            ->addIndex(['checkout_attempt_id'], ['name' => 'idx_gateway_references_checkout_attempt'])
            ->addForeignKey(
                'checkout_attempt_id',
                'checkout_attempts',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_gateway_references_checkout_attempt'],
            )
            ->update();
    }

    public function down(): void
    {
        $this->table('gateway_references')
            ->dropForeignKey('checkout_attempt_id', 'fk_gateway_references_checkout_attempt')
            ->removeIndexByName('idx_gateway_references_checkout_attempt')
            ->removeColumn('checkout_attempt_id')
            ->update();
    }
}
