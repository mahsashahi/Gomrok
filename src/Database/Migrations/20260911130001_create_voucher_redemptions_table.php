<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `voucher_redemptions` (Phase 17) — the reserve -> confirm/release lifecycle.
 * Identified by a caller-supplied `attempt_reference` (Phase 17 Q1 — Phase 20
 * will pass the payment id once payments exist). A `reserved` row counts
 * toward every usage cap from creation until it is `released`; there is no
 * automatic expiry in this phase (Phase 17 Q2 — a stale-reservation sweep is a
 * Phase 29 background job). See `.claude/Voucher.md`.
 */
final class CreateVoucherRedemptionsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('voucher_redemptions', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('voucher_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('client_user_ref', 'string', ['limit' => 120, 'null' => true])
            ->addColumn('attempt_reference', 'string', ['limit' => 191, 'null' => false])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'reserved'])
            ->addColumn('currency_code', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('price_minor', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('nominal_discount_minor', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('applied_discount_minor', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('payable_minor', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('reserved_at', 'datetime', ['null' => false])
            ->addColumn('confirmed_at', 'datetime', ['null' => true])
            ->addColumn('released_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['voucher_id', 'attempt_reference'], ['unique' => true, 'name' => 'uniq_voucher_redemptions_attempt'])
            ->addIndex(['voucher_id', 'client_user_ref', 'status'], ['name' => 'idx_voucher_redemptions_user'])
            ->addIndex(['voucher_id', 'client_id', 'status'], ['name' => 'idx_voucher_redemptions_client'])
            ->addIndex(['status'], ['name' => 'idx_voucher_redemptions_status'])
            ->addForeignKey('voucher_id', 'vouchers', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_voucher_redemptions_voucher'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_voucher_redemptions_client'])
            ->addForeignKey('currency_code', 'currencies', 'code', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_voucher_redemptions_currency'])
            ->create();
    }

    public function down(): void
    {
        $this->table('voucher_redemptions')->drop()->save();
    }
}
