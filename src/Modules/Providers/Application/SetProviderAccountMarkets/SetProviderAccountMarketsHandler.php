<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\SetProviderAccountMarkets;

use Gomrok\Modules\Providers\Application\ProviderAccountAuditSnapshot;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderAccountRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

final readonly class SetProviderAccountMarketsHandler
{
    public function __construct(
        private ProviderAccountRepository $accounts,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetProviderAccountMarketsCommand $command): Result
    {
        $account = $this->accounts->findById($command->accountId);
        if ($account === null) {
            return Result::err(DomainError::notFound('provider_account.not_found', "Provider account {$command->accountId} was not found."));
        }

        $methods = [];
        foreach ($command->methods as $methodValue) {
            $method = PaymentMethod::tryFrom($methodValue);
            if ($method === null) {
                return Result::err(DomainError::validation('provider_account.unknown_method', "Unknown payment method '{$methodValue}'.", ['method' => $methodValue]));
            }
            $methods[] = $method;
        }

        foreach ($command->countries as $country) {
            if (!$this->reference->countryExists($country)) {
                return Result::err(DomainError::validation('provider_account.unknown_country', "Country '{$country}' is not a configured market.", ['country' => $country]));
            }
        }

        $before = ProviderAccountAuditSnapshot::of($account);
        $now = $this->clock->now();

        if ($command->name !== null && trim($command->name) !== '') {
            $account->rename(trim($command->name), $now);
        }
        $account->changeMarkets($command->countries, $methods, $now);

        $clientId = $account->clientId();
        $this->transactions->run(function () use ($account, $before, $clientId, $command): void {
            $this->accounts->save($account);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'provider_account.markets_updated')
                : AuditEntry::forSystem('provider_account.markets_updated', $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('provider_account', $command->accountId)
                    ->withChange($before, ProviderAccountAuditSnapshot::of($account)),
            );
        });

        return Result::ok(null);
    }
}
