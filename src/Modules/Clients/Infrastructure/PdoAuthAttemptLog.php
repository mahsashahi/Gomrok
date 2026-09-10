<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Application\Authenticate\AuthAttempt;
use Gomrok\Modules\Clients\Application\Authenticate\AuthAttemptLog;
use PDO;

/**
 * MySQL {@see AuthAttemptLog} — a single append to `client_auth_attempts`.
 */
final readonly class PdoAuthAttemptLog implements AuthAttemptLog
{
    public function __construct(private PDO $pdo)
    {
    }

    public function record(AuthAttempt $attempt, DateTimeImmutable $at): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO client_auth_attempts
                (outcome, reason, key_id, client_id, ip, user_agent, correlation_id, created_at)
             VALUES
                (:outcome, :reason, :key_id, :client_id, :ip, :user_agent, :correlation_id, :created_at)',
        );
        $statement->execute([
            'outcome' => $attempt->outcome,
            'reason' => $attempt->reason->value,
            'key_id' => $attempt->keyId,
            'client_id' => $attempt->clientId,
            'ip' => $attempt->ip,
            'user_agent' => $attempt->userAgent,
            'correlation_id' => $attempt->correlationId,
            'created_at' => $at->format('Y-m-d H:i:s'),
        ]);
    }
}
