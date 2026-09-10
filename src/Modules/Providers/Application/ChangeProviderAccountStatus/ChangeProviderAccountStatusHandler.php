<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\ChangeProviderAccountStatus;

use Gomrok\Modules\Providers\Application\ProviderAccountAuditSnapshot;
use Gomrok\Modules\Providers\Domain\ProviderAccountRepository;
use Gomrok\Modules\Providers\Domain\ProviderAccountStatus;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Disable / enable a provider account (soft, reversible). Idempotent.
 */
final readonly class ChangeProviderAccountStatusHandler
{
    public function __construct(
        private ProviderAccountRepository $accounts,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function disable(int $accountId, ?string $reason = null, ?int $actorId = null): Result
    {
        return $this->apply($accountId, ProviderAccountStatus::Disabled, $reason, $actorId);
    }

    public function enable(int $accountId, ?int $actorId = null): Result
    {
        return $this->apply($accountId, ProviderAccountStatus::Active, null, $actorId);
    }

    private function apply(int $accountId, ProviderAccountStatus $target, ?string $reason, ?int $actorId): Result
    {
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            return Result::err(DomainError::notFound('provider_account.not_found', "Provider account {$accountId} was not found."));
        }

        if ($account->status() === $target) {
            return Result::ok(null);
        }

        $before = ProviderAccountAuditSnapshot::of($account);
        $now = $this->clock->now();
        $action = $target === ProviderAccountStatus::Disabled ? 'provider_account.disabled' : 'provider_account.enabled';

        if ($target === ProviderAccountStatus::Disabled) {
            $account->disable($actorId, $reason, $now);
        } else {
            $account->enable($now);
        }

        $clientId = $account->clientId();
        $this->transactions->run(function () use ($account, $before, $clientId, $accountId, $action, $actorId): void {
            $this->accounts->save($account);

            $entry = $actorId !== null
                ? AuditEntry::forAdminUser($actorId, $clientId, $action)
                : AuditEntry::forSystem($action, $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('provider_account', $accountId)
                    ->withChange($before, ProviderAccountAuditSnapshot::of($account)),
            );
        });

        return Result::ok(null);
    }
}
