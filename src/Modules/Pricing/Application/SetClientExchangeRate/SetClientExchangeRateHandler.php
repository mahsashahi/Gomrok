<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetClientExchangeRate;

use DateTimeImmutable;
use Exception;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\PricingAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\ClientExchangeRate;
use Gomrok\Modules\Pricing\Domain\ClientExchangeRateRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

final readonly class SetClientExchangeRateHandler
{
    public function __construct(
        private ClientExchangeRateRepository $rates,
        private ClientDirectory $clients,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SetClientExchangeRateCommand $command): Result
    {
        $currencies = [];
        foreach (['base' => $command->baseCurrency, 'quote' => $command->quoteCurrency] as $label => $raw) {
            try {
                $code = Currency::of($raw)->code();
            } catch (InvalidArgumentException) {
                return Result::err(DomainError::validation('pricing.invalid_currency', "'{$raw}' is not a valid ISO 4217 currency."));
            }
            if (!$this->reference->currencyExists($code)) {
                return Result::err(DomainError::validation('pricing.unknown_currency', "Currency '{$code}' is not configured.", ['currency' => $code]));
            }
            $currencies[$label] = $code;
        }
        if ($currencies['base'] === $currencies['quote']) {
            return Result::err(DomainError::validation('pricing.same_currency_rate', 'Base and quote currency must differ.'));
        }

        if (!is_numeric($command->rate) || (float) $command->rate <= 0) {
            return Result::err(DomainError::validation('pricing.invalid_rate', 'The rate must be a positive number.', ['rate' => $command->rate]));
        }

        try {
            $effectiveFrom = $command->effectiveFrom !== null
                ? new DateTimeImmutable($command->effectiveFrom)
                : $this->clock->now();
        } catch (Exception) {
            return Result::err(DomainError::validation('pricing.invalid_datetime', "'{$command->effectiveFrom}' is not a valid datetime."));
        }

        if (!$this->clients->existsById($command->clientId)) {
            return Result::err(DomainError::notFound('client.not_found', "Client {$command->clientId} was not found."));
        }

        $rate = new ClientExchangeRate(
            $command->clientId,
            $currencies['base'],
            $currencies['quote'],
            $command->rate,
            $effectiveFrom,
        );

        $clientId = $command->clientId;
        $this->transactions->run(function () use ($rate, $clientId, $command): void {
            $this->rates->save($rate);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'client_exchange_rate.set')
                : AuditEntry::forSystem('client_exchange_rate.set', $clientId);

            $this->audit->record($entry->withChange(null, PricingAuditSnapshot::rate($rate)));
        });

        return Result::ok(null);
    }
}
