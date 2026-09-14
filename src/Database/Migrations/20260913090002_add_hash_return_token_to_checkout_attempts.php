<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * Phase 24 Q4 (user-specified): the public return endpoint identifies a
 * checkout attempt via a `return_token` of the form
 * `{checkout_attempt_id}_{hash}`, where `hash = HMAC-SHA256(checkout_attempt_id,
 * secret)` — a correlation key Gomrok can compute before calling the
 * provider (Mollie/PayPal have no way to echo their own reference into a
 * success/cancel URL the way Stripe's `{CHECKOUT_SESSION_ID}` placeholder
 * does). Only the hash half is persisted; the id half is already the row's
 * own primary key. Not proof of payment — the return endpoint still verifies
 * actual status with the provider's own API.
 */
final class AddHashReturnTokenToCheckoutAttempts extends AbstractMigration
{
    public function up(): void
    {
        $this->table('checkout_attempts')
            ->addColumn('hash_return_token', 'string', ['limit' => 64, 'null' => true, 'after' => 'expired_at'])
            ->update();
    }

    public function down(): void
    {
        $this->table('checkout_attempts')
            ->removeColumn('hash_return_token')
            ->update();
    }
}
