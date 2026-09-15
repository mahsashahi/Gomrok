<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * Phase 26 Q2: a subscription renewal charge has no `checkout_attempts` row
 * of its own (it's triggered by the provider's own billing schedule, not a
 * customer checkout) but still becomes a real `payments` row, created via
 * `RecordSubscriptionPaymentHandler` instead of `CreatePaymentHandler`.
 * `checkout_attempt_id` moves from required to nullable; the `UNIQUE` index
 * stays (`payments.checkout_attempt_id`, Phase 20 Q1) — MySQL allows multiple
 * `NULL`s under a `UNIQUE` index, so a checkout-attempt-originated payment
 * still can't share its attempt with another payment, and renewal-originated
 * payments (both `NULL`) impose no such constraint on each other.
 */
final class MakePaymentsCheckoutAttemptIdNullable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('payments')
            ->changeColumn('checkout_attempt_id', 'integer', ['signed' => false, 'null' => true])
            ->update();
    }

    public function down(): void
    {
        $this->table('payments')
            ->changeColumn('checkout_attempt_id', 'integer', ['signed' => false, 'null' => false])
            ->update();
    }
}
