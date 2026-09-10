<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Persistence;

use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Infrastructure\SecretRedactor;
use PDO;
use Psr\Clock\ClockInterface;

/**
 * MySQL-backed {@see AuditLogWriter}. `before` / `after` / `context` are
 * secret-redacted, then JSON-encoded; `created_at` comes from the clock.
 *
 * An audit write failure is *not* swallowed — a lost audit trail on a sensitive
 * action should surface, unlike error-log writes.
 */
final readonly class PdoAuditLogWriter implements AuditLogWriter
{
    public function __construct(
        private PDO $pdo,
        private ClockInterface $clock,
    ) {
    }

    public function record(AuditEntry $entry): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs
                (actor_type, actor_id, client_id, action, target_type, target_id,
                 `before`, `after`, context, correlation_id, ip, user_agent, created_at)
             VALUES
                (:actor_type, :actor_id, :client_id, :action, :target_type, :target_id,
                 :before, :after, :context, :correlation_id, :ip, :user_agent, :created_at)',
        );

        $statement->execute([
            'actor_type' => $entry->actorType->value,
            'actor_id' => $entry->actorId,
            'client_id' => $entry->clientId,
            'action' => $entry->action,
            'target_type' => $entry->targetType,
            'target_id' => $entry->targetId,
            'before' => $this->json($entry->before),
            'after' => $this->json($entry->after),
            'context' => $this->json($entry->context),
            'correlation_id' => $entry->correlationId,
            'ip' => $entry->ip,
            'user_agent' => $entry->userAgent,
            'created_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function json(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        return json_encode(SecretRedactor::redact($data), JSON_THROW_ON_ERROR);
    }
}
