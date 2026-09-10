<?php

declare(strict_types=1);

namespace Gomrok\Database\Migrations;

use Phinx\Migration\AbstractMigration;

/**
 * `error_logs` — operator triage surface, written only by the explicit
 * `ErrorLogWriter` (never a log handler). Backs the admin Error Logs screen in
 * Phase 27; `resolved_at` / `resolved_by` support its "mark resolved" action.
 *
 * `client_id` carries no FK yet (see `CreateIdempotencyKeysTable`).
 */
final class CreateErrorLogsTable extends AbstractMigration
{
    public function up(): void
    {
        $this->table('error_logs', [
            'id' => 'id',
            'signed' => false,
            'engine' => 'InnoDB',
            'encoding' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ])
            ->addColumn('level', 'string', ['limit' => 20, 'null' => false])
            ->addColumn('source', 'string', ['limit' => 40, 'null' => false])
            ->addColumn('message', 'text', ['null' => false])
            ->addColumn('exception_class', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('code', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('client_id', 'integer', ['signed' => false, 'null' => true])
            ->addColumn('correlation_id', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('context', 'json', ['null' => true])
            ->addColumn('stack_trace', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('resolved_at', 'datetime', ['null' => true])
            ->addColumn('resolved_by', 'integer', ['signed' => false, 'null' => true])
            ->addIndex(['created_at'], ['name' => 'idx_error_logs_created'])
            ->addIndex(['client_id'], ['name' => 'idx_error_logs_client'])
            ->addIndex(['resolved_at'], ['name' => 'idx_error_logs_unresolved'])
            ->create();
    }

    public function down(): void
    {
        $this->table('error_logs')->drop()->save();
    }
}
