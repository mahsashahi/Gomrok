<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\ErrorLogs\SetErrorLogResolution;

use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ErrorLog\ErrorLogDirectory;
use Gomrok\Shared\Application\ErrorLog\ErrorLogResolver;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Idempotent — checked against the current state via {@see ErrorLogDirectory::find()}
 * before writing, both to return a clean "not found" and because
 * {@see ErrorLogResolver}'s plain `UPDATE` reports zero affected rows for a
 * no-op write (already in the target state) exactly like a missing row would,
 * and the two must not be confused.
 */
final readonly class SetErrorLogResolutionHandler
{
    public function __construct(
        private ErrorLogDirectory $errorLogs,
        private ErrorLogResolver $resolver,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetErrorLogResolutionCommand $command): Result
    {
        $record = $this->errorLogs->find($command->errorLogId);
        if ($record === null) {
            return Result::err(DomainError::notFound('error_log.not_found', "Error log {$command->errorLogId} was not found."));
        }

        if ($record->isResolved() === $command->resolved) {
            return Result::ok(null);
        }

        $now = $this->clock->now();
        $action = $command->resolved ? 'error_log.resolved' : 'error_log.reopened';

        $this->transactions->run(function () use ($command, $now, $action): void {
            if ($command->resolved) {
                $this->resolver->markResolved($command->errorLogId, $command->actorId, $now);
            } else {
                $this->resolver->markUnresolved($command->errorLogId);
            }

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, null, $action)
                : AuditEntry::forSystem($action);

            $this->audit->record($entry->withTarget('error_log', $command->errorLogId));
        });

        return Result::ok(null);
    }
}
