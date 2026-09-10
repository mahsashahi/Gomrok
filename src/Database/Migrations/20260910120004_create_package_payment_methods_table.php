<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `package_payment_methods` — the payment methods a package is sold via (Phase
 * 11 Q1). `payment_method` holds a `Gomrok\Modules\Providers\Domain\PaymentMethod`
 * value (app-enforced, no lookup table — same as `provider_group_methods`).
 * Empty set = every method (Q2 — fail open).
 */
final class CreatePackagePaymentMethodsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('package_payment_methods', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('package_id', 'integer', ['signed' => false, 'null' => false])
            ->addColumn('payment_method', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['package_id', 'payment_method'], ['unique' => true, 'name' => 'uniq_package_payment_methods'])
            ->addIndex(['payment_method'], ['name' => 'idx_package_payment_methods_method'])
            ->addForeignKey(
                'package_id',
                'packages',
                'id',
                ['delete' => 'CASCADE', 'update' => 'CASCADE', 'constraint' => 'fk_package_payment_methods_package'],
            )
            ->create();
    }

    public function down(): void
    {
        $this->table('package_payment_methods')->drop()->save();
    }
}
