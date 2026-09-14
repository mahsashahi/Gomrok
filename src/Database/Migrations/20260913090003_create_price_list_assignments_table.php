<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `price_list_assignments` — persisted visitor -> A/B price list bucketing
 * (Phase 15 Q4/Q5, re-asked and decided fresh at Phase 24 Q6/Q7). A visitor's
 * bucket is chosen once, on first sight, by a deterministic hash over the
 * pricing group's currently-enabled {@see \Gomrok\Modules\Pricing\Domain\PriceList}
 * rows, and then persisted so later visits are stable even as new lists are
 * added. `visitor_ref_hash` is `SHA-256(pricing_group_id . ':' . visitor_ref)`
 * — the raw `visitor_ref` is never stored. If the assigned list is later
 * disabled, the next read reassigns the row to the group's control list
 * (`reassigned_at` records when).
 */
final class CreatePriceListAssignmentsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('price_list_assignments', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('pricing_group_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('visitor_ref_hash', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('price_list_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('assigned_at', 'datetime', ['null' => false])
            ->addColumn('reassigned_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addIndex(['pricing_group_id', 'visitor_ref_hash'], ['unique' => true, 'name' => 'uniq_price_list_assignments_group_visitor'])
            ->addIndex(['client_id'], ['name' => 'idx_price_list_assignments_client'])
            ->addIndex(['price_list_id'], ['name' => 'idx_price_list_assignments_list'])
            ->addForeignKey('client_id', 'clients', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_price_list_assignments_client'])
            ->addForeignKey('pricing_group_id', 'pricing_groups', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_price_list_assignments_group'])
            ->addForeignKey('price_list_id', 'price_lists', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_price_list_assignments_list'])
            ->create();
    }

    public function down(): void
    {
        $this->table('price_list_assignments')->drop()->save();
    }
}
