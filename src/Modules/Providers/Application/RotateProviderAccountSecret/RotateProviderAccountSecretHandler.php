<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\RotateProviderAccountSecret;

use Gomrok\Modules\Providers\Application\ProviderAccountAuditSnapshot;
use Gomrok\Modules\Providers\Domain\EncryptedSecret;
use Gomrok\Modules\Providers\Domain\ProviderAccountRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\SecretCipher;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Replaces a provider account's secret (and optionally its public key). A
 * sensitive, audited action (`CLAUDE.md` → *Admin security*).
 */
final readonly class RotateProviderAccountSecretHandler
{
    public function __construct(
        private ProviderAccountRepository $accounts,
        private SecretCipher $cipher,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(RotateProviderAccountSecretCommand $command): Result
    {
        if (trim($command->newSecretKey) === '') {
            return Result::err(DomainError::validation('provider_account.secret_required', 'A secret key is required.'));
        }

        $account = $this->accounts->findById($command->accountId);
        if ($account === null) {
            return Result::err(DomainError::notFound('provider_account.not_found', "Provider account {$command->accountId} was not found."));
        }

        $before = ProviderAccountAuditSnapshot::of($account);
        $now = $this->clock->now();

        $account->rotateSecret(
            EncryptedSecret::fromParts($this->cipher->encrypt($command->newSecretKey), $command->newSecretKey),
            $now,
        );
        if ($command->replacePublicKey) {
            $account->replacePublicKey($command->newPublicKey, $now);
        }

        $clientId = $account->clientId();
        $this->transactions->run(function () use ($account, $before, $clientId, $command): void {
            $this->accounts->save($account);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'provider_account.secret_rotated')
                : AuditEntry::forSystem('provider_account.secret_rotated', $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('provider_account', $command->accountId)
                    ->withChange($before, ProviderAccountAuditSnapshot::of($account)),
            );
        });

        return Result::ok($account->secret()->lastFour);
    }
}
