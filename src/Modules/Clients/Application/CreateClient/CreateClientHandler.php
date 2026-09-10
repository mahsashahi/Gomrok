<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\CreateClient;

use Gomrok\Modules\Clients\Application\ClientAuditSnapshot;
use Gomrok\Modules\Clients\Domain\ApiKeyGenerator;
use Gomrok\Modules\Clients\Domain\Client;
use Gomrok\Modules\Clients\Domain\ClientApiKeyRepository;
use Gomrok\Modules\Clients\Domain\ClientRepository;
use Gomrok\Modules\Clients\Domain\ClientSlug;
use Gomrok\Modules\Clients\Domain\Events\ApiKeyIssued;
use Gomrok\Modules\Clients\Domain\Events\ClientCreated;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\TokenGenerator;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\CountryCode;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Creates a client and issues its first API key atomically. Returns
 * {@see CreateClientResult} (with the one-time token) or a {@see DomainError}.
 */
final readonly class CreateClientHandler
{
    private const SIGNING_SECRET_BYTES = 32;

    public function __construct(
        private ClientRepository $clients,
        private ClientApiKeyRepository $apiKeys,
        private ApiKeyGenerator $apiKeyGenerator,
        private TokenGenerator $tokens,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(CreateClientCommand $command): Result
    {
        if (!ClientSlug::isValid($command->slug)) {
            return Result::err(ClientSlug::error($command->slug));
        }

        $name = trim($command->name);
        if ($name === '') {
            return Result::err(DomainError::validation('client.name_required', 'A client name is required.'));
        }

        try {
            $currency = Currency::of($command->defaultCurrency);
            $country = $command->defaultCountry !== null ? CountryCode::of($command->defaultCountry) : null;
        } catch (InvalidArgumentException $e) {
            return Result::err(DomainError::validation('client.invalid_market', $e->getMessage()));
        }

        if (!$this->reference->currencyExists($currency->code())) {
            return Result::err(DomainError::validation(
                'client.unknown_currency',
                "Currency {$currency->code()} is not available.",
                ['currency' => $currency->code()],
            ));
        }

        if ($country !== null && !$this->reference->countryExists($country->value)) {
            return Result::err(DomainError::validation(
                'client.unknown_country',
                "Country {$country->value} is not a configured market.",
                ['country' => $country->value],
            ));
        }

        if ($this->clients->existsWithSlug($command->slug)) {
            return Result::err(DomainError::conflict(
                'client.slug_taken',
                "A client with slug '{$command->slug}' already exists.",
                ['slug' => $command->slug],
            ));
        }

        $now = $this->clock->now();
        $slug = ClientSlug::of($command->slug);

        $client = Client::register(
            $slug,
            $name,
            $currency,
            $country,
            trim($command->timezone) === '' ? 'UTC' : trim($command->timezone),
            $this->tokens->urlSafe(self::SIGNING_SECRET_BYTES),
            $now,
        );

        $result = $this->transactions->run(function () use ($client, $command, $now): CreateClientResult {
            $this->clients->save($client);

            $clientId = $client->id();
            \assert($clientId !== null);

            $generated = $this->apiKeyGenerator->generate(
                $clientId,
                $command->firstKeyPrefix,
                $command->firstKeyLabel,
                $now,
            );
            $this->apiKeys->save($generated->apiKey);

            $this->audit->record(
                AuditEntry::forSystem('client.created', $clientId)
                    ->withTarget('client', $clientId)
                    ->withChange(null, ClientAuditSnapshot::of($client)),
            );

            return new CreateClientResult(
                $clientId,
                (string) $client->slug(),
                $generated->apiKey->keyId(),
                $generated->plaintextToken,
                new ClientCreated($clientId, (string) $client->slug(), $now),
                new ApiKeyIssued($clientId, $generated->apiKey->keyId(), $command->firstKeyPrefix->value, $now),
            );
        });

        return Result::ok($result);
    }
}
