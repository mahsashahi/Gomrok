<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `vouchers` + `voucher_eligibility_rules` + `voucher_currency_discounts`
 * (Phase 16) — voucher definitions and the eligibility gate. Discount
 * calculation, `voucher_redemptions`, and the redemption lifecycle are
 * Phase 17; the voucher decision snapshot is Phase 18. See `.claude/Voucher.md`
 * for the full rule set.
 */
final class CreateVoucherTables extends AbstractMigration
{
    public function up(): void
    {
        $this->table('vouchers', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('code', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 150, 'null' => false])
            ->addColumn('description', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('status', 'string', ['limit' => 20, 'null' => false, 'default' => 'active'])
            ->addColumn('valid_from', 'datetime', ['null' => true])
            ->addColumn('valid_until', 'datetime', ['null' => true])
            ->addColumn('first_purchase_only', 'boolean', ['null' => false, 'default' => false])
            ->addColumn('min_purchase_minor', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('min_purchase_currency', 'char', ['limit' => 3, 'null' => true])
            ->addColumn('default_discount_type', 'string', ['limit' => 20, 'null' => false, 'default' => 'none'])
            ->addColumn('default_percent_bp', 'smallinteger', ['signed' => false, 'null' => true])
            ->addColumn('max_total_redemptions', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('max_per_user', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('max_per_client', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('redeemed_count', 'integer', ['signed' => false, 'null' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['client_id', 'code'], ['unique' => true, 'name' => 'uniq_vouchers_client_code'])
            ->addIndex(['client_id', 'status'], ['name' => 'idx_vouchers_client_status'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_vouchers_client'])
            ->addForeignKey('min_purchase_currency', 'currencies', 'code', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_vouchers_min_purchase_currency'])
            ->create();

        $this->table('voucher_eligibility_rules', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('voucher_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('dimension', 'string', ['limit' => 30, 'null' => false])
            ->addColumn('value', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['voucher_id', 'dimension', 'value'], ['unique' => true, 'name' => 'uniq_voucher_eligibility'])
            ->addIndex(['voucher_id', 'dimension'], ['name' => 'idx_voucher_eligibility_dim'])
            ->addForeignKey('voucher_id', 'vouchers', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_voucher_eligibility_voucher'])
            ->create();

        $this->table('voucher_currency_discounts', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('voucher_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('currency_code', 'char', ['limit' => 3, 'null' => false])
            ->addColumn('discount_type', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('percent_bp', 'smallinteger', ['signed' => false, 'null' => true])
            ->addColumn('amount_minor', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('max_discount_minor', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['voucher_id', 'currency_code'], ['unique' => true, 'name' => 'uniq_voucher_currency_discounts'])
            ->addForeignKey('voucher_id', 'vouchers', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_voucher_currency_discounts_voucher'])
            ->addForeignKey('currency_code', 'currencies', 'code', ['delete' => 'RESTRICT', 'update' => 'CASCADE', 'constraint' => 'fk_voucher_currency_discounts_currency'])
            ->create();
    }

    public function down(): void
    {
        $this->table('voucher_currency_discounts')->drop()->save();
        $this->table('voucher_eligibility_rules')->drop()->save();
        $this->table('vouchers')->drop()->save();
    }
}
