<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\SelectCheckoutProvider;

use Gomrok\Modules\Checkout\Application\CheckoutAuditSnapshot;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptStatus;
use Gomrok\Modules\Providers\Application\Routing\ProviderRouter;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshot;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshotRepository;
use Gomrok\Modules\Providers\Application\Routing\RoutingDecision;
use Gomrok\Modules\Providers\Application\Routing\RoutingRequest;
use Gomrok\Modules\Providers\Domain\DeviceType;
use Gomrok\Modules\Providers\Domain\ProviderAccountMode;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Resolves which provider account handles a checkout attempt (Phase 10's
 * {@see ProviderRouter}), freezes a {@see ProviderRoutingDecisionSnapshot}, and
 * advances the attempt to `provider_selected`. Requires the attempt to already
 * have a resolved purchase type. Idempotent.
 */
final readonly class SelectCheckoutProviderHandler
{
    public function __construct(
        private CheckoutAttemptRepository $attempts,
        private ProviderRoutingDecisionSnapshotRepository $snapshots,
        private ProviderRouter $router,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(SelectCheckoutProviderCommand $command): Result
    {
        $attempt = $this->attempts->findById($command->checkoutAttemptId);
        if ($attempt === null || $attempt->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('checkout_attempt.not_found', "Checkout attempt {$command->checkoutAttemptId} was not found for this client."));
        }

        $existing = $this->snapshots->findByCheckoutAttemptId($command->checkoutAttemptId);
        if ($existing !== null) {
            return Result::ok(new SelectCheckoutProviderResult($existing->providerAccountId, $existing->paymentMethod, $existing->purchaseType));
        }

        $purchaseType = $attempt->purchaseType();
        if ($purchaseType === null) {
            return Result::err(DomainError::validation('checkout_attempt.purchase_type_required', 'A purchase type must be resolved before selecting a provider.'));
        }

        $mode = ProviderAccountMode::tryFrom($command->mode);
        if ($mode === null) {
            return Result::err(DomainError::validation('checkout_attempt.unknown_mode', "Unknown provider mode '{$command->mode}'.", ['mode' => $command->mode]));
        }

        $deviceType = null;
        if ($command->deviceType !== null) {
            $deviceType = DeviceType::tryFrom($command->deviceType);
            if ($deviceType === null) {
                return Result::err(DomainError::validation('checkout_attempt.unknown_device_type', "Unknown device type '{$command->deviceType}'.", ['device_type' => $command->deviceType]));
            }
        }

        $routingResult = $this->router->route(new RoutingRequest(
            $command->clientId,
            $attempt->country(),
            $attempt->currencyCode(),
            $purchaseType,
            $mode,
            $attempt->paymentMethod(),
            $deviceType,
        ));
        if ($routingResult->isErr()) {
            return $routingResult;
        }
        $decision = $routingResult->value();
        \assert($decision instanceof RoutingDecision);

        $now = $this->clock->now();
        $snapshot = ProviderRoutingDecisionSnapshot::of($command->checkoutAttemptId, $decision, $now);

        $before = CheckoutAuditSnapshot::attempt($attempt);
        $error = $attempt->transitionTo(CheckoutAttemptStatus::ProviderSelected, $now);
        if ($error !== null) {
            return Result::err($error);
        }

        $this->transactions->run(function () use ($snapshot, $attempt, $before, $command): void {
            $this->snapshots->save($snapshot);
            $this->attempts->save($attempt);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'checkout_attempt.provider_selected')
                : AuditEntry::forSystem('checkout_attempt.provider_selected', $command->clientId);

            $attemptId = $attempt->id();
            \assert($attemptId !== null);
            $this->audit->record($entry->withTarget('checkout_attempt', $attemptId)->withChange($before, CheckoutAuditSnapshot::attempt($attempt)));
        });

        return Result::ok(new SelectCheckoutProviderResult($snapshot->providerAccountId, $snapshot->paymentMethod, $snapshot->purchaseType));
    }
}
