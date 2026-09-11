<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\CreateCheckoutAttempt;

use Gomrok\Modules\Checkout\Application\CheckoutAuditSnapshot;
use Gomrok\Modules\Checkout\Domain\CheckoutAttempt;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Starts a checkout attempt: validates the client, package ownership, country
 * and currency, and the optional purchase-type/method/interval hints. A repeat
 * call with the same `(client_id, attempt_reference)` returns the existing
 * attempt unchanged (idempotent — Phase 18 Q1).
 */
final readonly class CreateCheckoutAttemptHandler
{
    public function __construct(
        private CheckoutAttemptRepository $attempts,
        private ClientDirectory $clients,
        private PackageDirectory $packages,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(CreateCheckoutAttemptCommand $command): Result
    {
        $attemptReference = trim($command->attemptReference);
        if ($attemptReference === '') {
            return Result::err(DomainError::validation('checkout_attempt.attempt_reference_required', 'An attempt reference is required.'));
        }

        $client = $this->clients->findById($command->clientId);
        if ($client === null) {
            return Result::err(DomainError::notFound('client.not_found', "Client {$command->clientId} was not found."));
        }
        if (!$client->isActive()) {
            return Result::err(DomainError::forbidden('client.disabled', 'Cannot start a checkout attempt for a disabled client.'));
        }

        $existing = $this->attempts->findByAttemptReference($command->clientId, $attemptReference);
        if ($existing !== null) {
            $id = $existing->id();
            \assert($id !== null);

            return Result::ok(new CreateCheckoutAttemptResult($id, $existing->status()->value));
        }

        $package = $this->packages->findById($command->packageId);
        if ($package === null || $package->clientId !== $command->clientId) {
            return Result::err(DomainError::notFound('package.not_found', "Package {$command->packageId} was not found for this client."));
        }

        $country = strtoupper(trim($command->country));
        if (!$this->reference->countryExists($country)) {
            return Result::err(DomainError::validation('checkout_attempt.unknown_country', "Country '{$country}' is not a configured market.", ['country' => $country]));
        }

        try {
            $currency = Currency::of($command->currencyCode)->code();
        } catch (InvalidArgumentException) {
            return Result::err(DomainError::validation('checkout_attempt.invalid_currency', "'{$command->currencyCode}' is not a valid ISO 4217 currency."));
        }
        if (!$this->reference->currencyExists($currency)) {
            return Result::err(DomainError::validation('checkout_attempt.unknown_currency', "Currency '{$currency}' is not configured.", ['currency' => $currency]));
        }

        $purchaseType = null;
        if ($command->purchaseType !== null) {
            $purchaseType = PurchaseType::tryFrom($command->purchaseType);
            if ($purchaseType === null) {
                return Result::err(DomainError::validation('checkout_attempt.unknown_purchase_type', "Unknown purchase type '{$command->purchaseType}'.", ['purchase_type' => $command->purchaseType]));
            }
        }

        $paymentMethod = null;
        if ($command->paymentMethod !== null) {
            $paymentMethod = PaymentMethod::tryFrom($command->paymentMethod);
            if ($paymentMethod === null) {
                return Result::err(DomainError::validation('checkout_attempt.unknown_method', "Unknown payment method '{$command->paymentMethod}'.", ['method' => $command->paymentMethod]));
            }
        }

        $interval = null;
        if ($command->subscriptionInterval !== null) {
            $interval = SubscriptionInterval::tryFrom($command->subscriptionInterval);
            if ($interval === null) {
                return Result::err(DomainError::validation('checkout_attempt.unknown_interval', "Unknown subscription interval '{$command->subscriptionInterval}'.", ['interval' => $command->subscriptionInterval]));
            }
        }

        $now = $this->clock->now();
        $attempt = CheckoutAttempt::start(
            $command->clientId,
            $command->clientUserRef,
            $attemptReference,
            $command->packageId,
            $country,
            $currency,
            $purchaseType,
            $paymentMethod,
            $interval,
            $now,
        );

        $this->transactions->run(function () use ($attempt, $command): void {
            $this->attempts->save($attempt);
            $attemptId = $attempt->id();
            \assert($attemptId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'checkout_attempt.started')
                : AuditEntry::forSystem('checkout_attempt.started', $command->clientId);

            $this->audit->record($entry->withTarget('checkout_attempt', $attemptId)->withChange(null, CheckoutAuditSnapshot::attempt($attempt)));
        });

        $attemptId = $attempt->id();
        \assert($attemptId !== null);

        return Result::ok(new CreateCheckoutAttemptResult($attemptId, $attempt->status()->value));
    }
}
