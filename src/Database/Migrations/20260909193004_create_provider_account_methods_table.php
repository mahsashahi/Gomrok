<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_account_methods` — which payment methods an account offers (Phase 9
 * Q4). `payment_method` holds a `Gomrok\Modules\Providers\Domain\PaymentMethod`
 * value (app-enforced). Used by the Phase 10 router.
 */
final class CreateProviderAccountMethodsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_account_methods', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('provider_account_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('payment_method', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['provider_account_id', 'payment_method'], ['unique' => true, 'name' => 'uniq_provider_account_methods'])
            ->addIndex(['payment_method'], ['name' => 'idx_provider_account_methods_method'])
            ->addForeignKey(
                'provider_account_id',
                'provider_accounts',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_provider_account_methods_account_id'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_account_methods')->drop()->save();
    }
}
