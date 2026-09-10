<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\UpdateClient;

use Gomrok\Modules\Clients\Application\ClientAuditSnapshot;
use Gomrok\Modules\Clients\Domain\ClientRepository;
use Gomrok\Modules\Clients\Domain\Events\ClientUpdated;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\CountryCode;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Updates a client's name / market defaults. Returns {@see ClientUpdated} (with
 * the list of changed fields) or a {@see DomainError}.
 */
final readonly class UpdateClientHandler
{
    public function __construct(
        private ClientRepository $clients,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(UpdateClientCommand $command): Result
    {
        $client = $this->clients->findById($command->clientId);
        if ($client === null) {
            return Result::err(DomainError::notFound('client.not_found', "Client {$command->clientId} was not found."));
        }

        $before = ClientAuditSnapshot::of($client);
        $now = $this->clock->now();
        $changed = [];

        if ($command->name !== null) {
            $name = trim($command->name);
            if ($name === '') {
                return Result::err(DomainError::validation('client.name_required', 'A client name is required.'));
            }
            if ($name !== $client->name()) {
                $client->rename($name, $now);
                $changed[] = 'name';
            }
        }

        $currency = $client->defaultCurrency();
        $country = $client->defaultCountry();
        $timezone = $client->timezone();
        $defaultsTouched = false;

        if ($command->defaultCurrency !== null) {
            try {
                $currency = Currency::of($command->defaultCurrency);
            } catch (InvalidArgumentException $e) {
                return Result::err(DomainError::validation('client.invalid_market', $e->getMessage()));
            }
            if (!$this->reference->currencyExists($currency->code())) {
                return Result::err(DomainError::validation('client.unknown_currency', "Currency {$currency->code()} is not available.", ['currency' => $currency->code()]));
            }
            $defaultsTouched = true;
            $changed[] = 'default_currency';
        }

        if ($command->clearDefaultCountry) {
            $country = null;
            $defaultsTouched = true;
            $changed[] = 'default_country';
        } elseif ($command->defaultCountry !== null) {
            try {
                $country = CountryCode::of($command->defaultCountry);
            } catch (InvalidArgumentException $e) {
                return Result::err(DomainError::validation('client.invalid_market', $e->getMessage()));
            }
            if (!$this->reference->countryExists($country->value)) {
                return Result::err(DomainError::validation('client.unknown_country', "Country {$country->value} is not a configured market.", ['country' => $country->value]));
            }
            $defaultsTouched = true;
            $changed[] = 'default_country';
        }

        if ($command->timezone !== null && trim($command->timezone) !== '' && trim($command->timezone) !== $timezone) {
            $timezone = trim($command->timezone);
            $defaultsTouched = true;
            $changed[] = 'timezone';
        }

        if ($defaultsTouched) {
            $client->changeDefaults($currency, $country, $timezone, $now);
        }

        if ($changed === []) {
            return Result::ok(new ClientUpdated($command->clientId, (string) $client->slug(), [], $now));
        }

        $clientId = $command->clientId;
        $this->transactions->run(function () use ($client, $before, $clientId): void {
            $this->clients->save($client);
            $this->audit->record(
                AuditEntry::forSystem('client.updated', $clientId)
                    ->withTarget('client', $clientId)
                    ->withChange($before, ClientAuditSnapshot::of($client)),
            );
        });

        return Result::ok(new ClientUpdated($command->clientId, (string) $client->slug(), $changed, $now));
    }
}
