<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\CreateProviderAccount;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountAuditSnapshot;
use Gomrok\Modules\Providers\Application\ProviderCatalog;
use Gomrok\Modules\Providers\Domain\EncryptedSecret;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\ProviderAccount;
use Gomrok\Modules\Providers\Domain\ProviderAccountMode;
use Gomrok\Modules\Providers\Domain\ProviderAccountRepository;
use Gomrok\Modules\Providers\Domain\ProviderAccountSlug;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\SecretCipher;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;
use ValueError;

/**
 * Creates a provider account: validates the client / provider type / markets,
 * encrypts the secret, persists, audits. Returns {@see CreateProviderAccountResult}
 * or a {@see DomainError}.
 */
final readonly class CreateProviderAccountHandler
{
    public function __construct(
        private ProviderAccountRepository $accounts,
        private ClientDirectory $clients,
        private ProviderCatalog $providerTypes,
        private ReferenceCatalog $reference,
        private SecretCipher $cipher,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(CreateProviderAccountCommand $command): Result
    {
        try {
            $mode = ProviderAccountMode::from($command->mode);
        } catch (ValueError) {
            return Result::err(DomainError::validation('provider_account.invalid_mode', "Mode must be 'live' or 'test'.", ['mode' => $command->mode]));
        }

        $slugValue = $command->slug !== null && $command->slug !== ''
            ? $command->slug
            : $command->providerTypeCode . '-' . $mode->value;
        if (!ProviderAccountSlug::isValid($slugValue)) {
            return Result::err(ProviderAccountSlug::error($slugValue));
        }

        $name = trim($command->name);
        if ($name === '') {
            return Result::err(DomainError::validation('provider_account.name_required', 'A name is required.'));
        }
        if (trim($command->secretKey) === '') {
            return Result::err(DomainError::validation('provider_account.secret_required', 'A secret key is required.'));
        }

        $client = $this->clients->findById($command->clientId);
        if ($client === null) {
            return Result::err(DomainError::notFound('client.not_found', "Client {$command->clientId} was not found."));
        }
        if (!$client->isActive()) {
            return Result::err(DomainError::forbidden('client.disabled', 'Cannot add a provider account to a disabled client.'));
        }

        $providerType = $this->providerTypes->find($command->providerTypeCode);
        if ($providerType === null) {
            return Result::err(DomainError::validation('provider_account.unknown_provider_type', "Unknown provider type '{$command->providerTypeCode}'.", ['provider_type' => $command->providerTypeCode]));
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

        if ($this->accounts->existsForClientWithSlug($command->clientId, $slugValue)) {
            return Result::err(DomainError::conflict('provider_account.slug_taken', "This client already has a provider account '{$slugValue}'.", ['slug' => $slugValue]));
        }

        $now = $this->clock->now();
        $account = ProviderAccount::register(
            $command->clientId,
            $providerType->id,
            ProviderAccountSlug::of($slugValue),
            $name,
            $mode,
            $command->publicKey,
            EncryptedSecret::fromParts($this->cipher->encrypt($command->secretKey), $command->secretKey),
            $command->countries,
            $methods,
            $now,
        );

        $this->transactions->run(function () use ($account, $command): void {
            $this->accounts->save($account);
            $accountId = $account->id();
            \assert($accountId !== null);

            $this->audit->record(
                AuditEntry::forSystem('provider_account.created', $command->clientId)
                    ->withTarget('provider_account', $accountId)
                    ->withChange(null, ProviderAccountAuditSnapshot::of($account)),
            );
        });

        $accountId = $account->id();
        \assert($accountId !== null);

        return Result::ok(new CreateProviderAccountResult(
            $accountId,
            $slugValue,
            $mode->value,
            $account->secret()->lastFour,
        ));
    }
}
