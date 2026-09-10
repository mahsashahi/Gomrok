<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `provider_types` — the payment providers Gomrok integrates with. Low-churn
 * (adding one is a whole phase). `requires_registration` = a product/price
 * object must exist provider-side before selling; `api_capable` = exposes an API.
 * No timestamps.
 */
final class CreateProviderTypesTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('provider_types', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('code', 'string', ['limit' => 32, 'null' => false])
            ->addColumn('name', 'string', ['limit' => 64, 'null' => false])
            ->addColumn('requires_registration', 'boolean', ['null' => false])
            ->addColumn('api_capable', 'boolean', ['null' => false])
            ->addIndex(['code'], ['unique' => true, 'name' => 'uniq_provider_types_code'])
            ->create();
    }

    public function down(): void
    {
        $this->table('provider_types')->drop()->save();
    }
}
