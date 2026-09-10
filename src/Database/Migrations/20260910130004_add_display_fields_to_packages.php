<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * Adds the display / reference fields Phase 11 deferred (Phase 12 Q1):
 * `badge` (short label), `highlighted` (catalogue emphasis), and
 * `client_package_id` (the client's own identifier for this package, used for
 * reconciliation — not unique-enforced by Gomrok). All additive: nullable or
 * defaulted, no backfill.
 */
final class AddDisplayFieldsToPackages extends AbstractMigration
{
    public function up(): void
    {
        $this->table('packages')
            ->addColumn('badge', 'string', ['limit' => 40, 'null' => true, 'after' => 'metadata'])
            ->addColumn('highlighted', 'boolean', ['null' => false, 'default' => false, 'after' => 'badge'])
            ->addColumn('client_package_id', 'string', ['limit' => 64, 'null' => true, 'after' => 'highlighted'])
            ->update();
    }

    public function down(): void
    {
        $this->table('packages')
            ->removeColumn('badge')
            ->removeColumn('highlighted')
            ->removeColumn('client_package_id')
            ->update();
    }
}
