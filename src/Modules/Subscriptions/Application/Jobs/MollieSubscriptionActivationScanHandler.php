<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Application\Jobs;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Providers\Application\Adapter\ActivateSubscriptionCommand;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterException;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\Adapter\SupportsDeferredSubscriptionActivation;
use Gomrok\Modules\Providers\Application\Adapter\UnsupportedProviderType;
use Gomrok\Modules\Providers\Domain\EndpointKind;
use Gomrok\Modules\Providers\Domain\ProviderAccountRepository;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionRepository;
use Gomrok\Shared\Application\Jobs\JobHandler;
use Gomrok\Shared\Application\Jobs\JobRunResult;
use Gomrok\Shared\Domain\Jobs\Job;
use Psr\Clock\ClockInterface;

/**
 * Phase 29 Q2's Mollie half: for a subscription whose first-payment mandate
 * is confirmed (`active`/`trialing`) but whose real Mollie Subscription
 * resource was never created, activate it — after this, Mollie pushes its
 * own renewal webhooks going forward, resolved by the same
 * `ProcessWebhookEventHandler` fix Stripe's billing engine already uses.
 * Deliberately a scan job, not something the first-payment webhook does
 * inline: creating the real subscription is an outbound provider call, and
 * CLAUDE.md's "don't block a webhook response on slow work" applies here
 * exactly as it did for Phase 28's notification delivery.
 */
final readonly class MollieSubscriptionActivationScanHandler implements JobHandler
{
    private const BATCH_SIZE = 50;
    private const RECURRENCE_MINUTES = 10;

    public function __construct(
        private SubscriptionRepository $subscriptions,
        private GatewayReferenceRepository $gatewayReferences,
        private ProviderAccountRepository $providerAccounts,
        private PackageDirectory $packages,
        private ProviderAdapterFactory $adapterFactory,
        private ClockInterface $clock,
        private string $appBaseUrl,
    ) {
    }

    public function type(): string
    {
        return 'mollie_subscription_activation_scan';
    }

    public function recurrenceIntervalMinutes(): int
    {
        return self::RECURRENCE_MINUTES;
    }

    public function handle(Job $job): JobRunResult
    {
        $pending = $this->subscriptions->findPendingMollieActivation(self::BATCH_SIZE);

        $activated = 0;
        $skipped = 0;
        foreach ($pending as $subscription) {
            if ($this->activateOne($subscription)) {
                ++$activated;
            } else {
                ++$skipped;
            }
        }

        return JobRunResult::success(['scanned' => \count($pending), 'activated' => $activated, 'skipped' => $skipped]);
    }

    private function activateOne(Subscription $subscription): bool
    {
        $subscriptionId = $subscription->id();
        if ($subscriptionId === null) {
            return false;
        }

        $customerId = $this->findCustomerId($subscription);
        if ($customerId === null) {
            // No Mollie customer id was ever recorded for this subscription's
            // origin checkout attempt — nothing to activate against yet.
            return false;
        }

        try {
            $adapter = $this->adapterFactory->for($subscription->providerAccountId());
        } catch (UnsupportedProviderType) {
            return false;
        }
        if (!$adapter instanceof SupportsDeferredSubscriptionActivation) {
            return false;
        }

        $package = $this->packages->findById($subscription->packageId());
        $description = $package !== null ? $package->name : "Package #{$subscription->packageId()}";
        $webhookUrl = $this->webhookUrlFor($subscription->providerAccountId());

        try {
            $result = $adapter->activateSubscription($customerId, new ActivateSubscriptionCommand(
                amountMinor: $subscription->amountMinor(),
                currencyCode: $subscription->currencyCode(),
                description: $description,
                interval: $subscription->interval(),
                webhookUrl: $webhookUrl,
            ));
        } catch (ProviderAdapterException) {
            return false;
        }

        $this->gatewayReferences->save(GatewayReference::forSubscription(
            $subscription->clientId(),
            $subscription->providerAccountId(),
            GatewayReferenceType::Subscription,
            $result->providerReference,
            $subscriptionId,
            $this->clock->now(),
        ));

        return true;
    }

    private function findCustomerId(Subscription $subscription): ?string
    {
        foreach ($this->gatewayReferences->forCheckoutAttempt($subscription->checkoutAttemptId()) as $reference) {
            if ($reference->referenceType === GatewayReferenceType::Customer) {
                return $reference->referenceValue;
            }
        }

        return null;
    }

    private function webhookUrlFor(int $providerAccountId): ?string
    {
        $account = $this->providerAccounts->findById($providerAccountId);
        if ($account === null) {
            return null;
        }

        foreach ($account->endpoints() as $endpoint) {
            if ($endpoint->kind() === EndpointKind::Webhook && $endpoint->isActive() && $endpoint->token() !== null) {
                return rtrim($this->appBaseUrl, '/') . '/api/v1/webhooks/mollie/' . $endpoint->token();
            }
        }

        return null;
    }
}
