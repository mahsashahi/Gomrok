<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\ResolveCheckoutPricing;

use Gomrok\Modules\Checkout\Application\CheckoutAuditSnapshot;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshot;
use Gomrok\Modules\Pricing\Application\PricingDecisionSnapshotRepository;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Resolves the checkout attempt's price (Pricing module, Phases 13–15),
 * freezes it into a {@see PricingDecisionSnapshot}, and advances the attempt to
 * `pricing_resolved`. Idempotent: a repeat call for an attempt that already has
 * a pricing snapshot returns it unchanged without re-resolving.
 */
final readonly class ResolveCheckoutPricingHandler
{
    public function __construct(
        private CheckoutAttemptRepository $attempts,
        private PricingDecisionSnapshotRepository $snapshots,
        private PriceResolver $resolver,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(ResolveCheckoutPricingCommand $command): Result
    {
        $attempt = $this->attempts->findById($command->checkoutAttemptId);
        if ($attempt === null || $attempt->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('checkout_attempt.not_found', "Checkout attempt {$command->checkoutAttemptId} was not found for this client."));
        }

        $existing = $this->snapshots->findByCheckoutAttemptId($command->checkoutAttemptId);
        if ($existing !== null) {
            return Result::ok(new ResolveCheckoutPricingResult($existing->amountMinor, $existing->currencyCode, $existing->source));
        }

        $priceResult = $this->resolver->resolve(
            $command->clientId,
            $attempt->packageId(),
            $attempt->country(),
            $command->deviceType,
            $attempt->paymentMethod(),
            $attempt->purchaseType(),
            $attempt->subscriptionInterval(),
            $command->providerAccountId,
            $command->priceListId,
        );
        if ($priceResult->isErr()) {
            return $priceResult;
        }
        $price = $priceResult->value();
        \assert($price instanceof ResolvedPrice);

        $now = $this->clock->now();
        $snapshot = PricingDecisionSnapshot::of($command->checkoutAttemptId, $command->clientId, $price, $now);

        $before = CheckoutAuditSnapshot::attempt($attempt);
        $error = $attempt->transitionTo(CheckoutAttemptStatus::PricingResolved, $now);
        if ($error !== null) {
            return Result::err($error);
        }

        $this->transactions->run(function () use ($snapshot, $attempt, $before, $command): void {
            $this->snapshots->save($snapshot);
            $this->attempts->save($attempt);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'checkout_attempt.pricing_resolved')
                : AuditEntry::forSystem('checkout_attempt.pricing_resolved', $command->clientId);

            $attemptId = $attempt->id();
            \assert($attemptId !== null);
            $this->audit->record($entry->withTarget('checkout_attempt', $attemptId)->withChange($before, CheckoutAuditSnapshot::attempt($attempt)));
        });

        return Result::ok(new ResolveCheckoutPricingResult($price->amountMinor, $price->currencyCode, $price->source->value));
    }
}
