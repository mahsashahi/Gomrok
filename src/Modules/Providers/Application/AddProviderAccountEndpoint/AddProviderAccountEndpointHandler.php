<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\AddProviderAccountEndpoint;

use Gomrok\Modules\Providers\Application\ProviderAccountAuditSnapshot;
use Gomrok\Modules\Providers\Application\ProviderCatalog;
use Gomrok\Modules\Providers\Domain\EndpointKind;
use Gomrok\Modules\Providers\Domain\ProviderAccountEndpoint;
use Gomrok\Modules\Providers\Domain\ProviderAccountRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\SecretCipher;
use Gomrok\Shared\Application\TokenGenerator;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;
use ValueError;

/**
 * Adds an inbound endpoint (webhook / callback / return) to a provider account,
 * generating a routing token for the kinds that need one and encrypting the
 * signing secret.
 */
final readonly class AddProviderAccountEndpointHandler
{
    private const TOKEN_BYTES = 24;

    public function __construct(
        private ProviderAccountRepository $accounts,
        private ProviderCatalog $providerTypes,
        private TokenGenerator $tokens,
        private SecretCipher $cipher,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(AddProviderAccountEndpointCommand $command): Result
    {
        try {
            $kind = EndpointKind::from($command->kind);
        } catch (ValueError) {
            return Result::err(DomainError::validation('provider_account.unknown_endpoint_kind', "Unknown endpoint kind '{$command->kind}'.", ['kind' => $command->kind]));
        }

        $account = $this->accounts->findById($command->accountId);
        if ($account === null) {
            return Result::err(DomainError::notFound('provider_account.not_found', "Provider account {$command->accountId} was not found."));
        }

        $token = $kind->needsToken() ? 'whk_' . $this->tokens->urlSafe(self::TOKEN_BYTES) : null;
        $signingSecretCiphertext = $command->signingSecret !== null && $command->signingSecret !== ''
            ? $this->cipher->encrypt($command->signingSecret)
            : null;

        $before = ProviderAccountAuditSnapshot::of($account);
        $now = $this->clock->now();

        $endpoint = ProviderAccountEndpoint::create($kind, $token, $signingSecretCiphertext);
        $account->addEndpoint($endpoint, $now);

        $clientId = $account->clientId();
        $this->transactions->run(function () use ($account, $before, $clientId, $command, $kind): void {
            $this->accounts->save($account);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'provider_account.endpoint_added')
                : AuditEntry::forSystem('provider_account.endpoint_added', $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('provider_account', $command->accountId)
                    ->withChange($before, ProviderAccountAuditSnapshot::of($account))
                    ->withContext(['kind' => $kind->value]),
            );
        });

        $endpointId = $endpoint->id();
        \assert($endpointId !== null);

        $inboundPath = null;
        if ($token !== null) {
            $code = $this->providerCodeFor($account->providerTypeId());
            $inboundPath = $code !== null ? "/api/v1/webhooks/{$code}/{$token}" : null;
        }

        return Result::ok(new AddProviderAccountEndpointResult($endpointId, $kind->value, $token, $inboundPath));
    }

    private function providerCodeFor(int $providerTypeId): ?string
    {
        foreach ($this->providerTypes->all() as $summary) {
            if ($summary->id === $providerTypeId) {
                return $summary->code;
            }
        }

        return null;
    }
}
