<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_group_methods` — the payment methods a {@see provider_groups} row
 * allows (Phase 10 Q2). `payment_method` holds a
 * `Gomrok\Modules\Providers\Domain\PaymentMethod` value (app-enforced). An empty
 * set = every method the resolved account supports (method is a refinement, not
 * a gate — fail open).
 */
final class CreateProviderGroupMethodsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_group_methods', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('provider_group_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('payment_method', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['provider_group_id', 'payment_method'], ['unique' => true, 'name' => 'uniq_provider_group_methods'])
            ->addIndex(['payment_method'], ['name' => 'idx_provider_group_methods_method'])
            ->addForeignKey(
                'provider_group_id',
                'provider_groups',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_group_methods_group'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_group_methods')->drop()->save();
    }
}
