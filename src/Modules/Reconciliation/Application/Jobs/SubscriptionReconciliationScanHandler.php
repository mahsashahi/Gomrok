<?php

declare(strict_types=1);

namespace Gomrok\Modules\Reconciliation\Application\Jobs;

use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterException;
use Gomrok\Modules\Providers\Application\Adapter\SupportsSubscriptions;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFinding;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFindingRepository;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationTargetType;
use Gomrok\Modules\Subscriptions\Application\ResolveSubscriptionActionContext;
use Gomrok\Modules\Subscriptions\Domain\Subscription;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionRepository;
use Gomrok\Shared\Application\Jobs\JobHandler;
use Gomrok\Shared\Application\Jobs\JobRunResult;
use Gomrok\Shared\Domain\Jobs\Job;
use Psr\Clock\ClockInterface;

/**
 * Polls each recently-touched subscription's real provider status and
 * records a {@see ReconciliationFinding} when it disagrees with Gomrok's
 * local status. Reuses {@see ResolveSubscriptionActionContext} unchanged
 * (Phase 26) for reference resolution — the same "prefer the real
 * Subscription-resource reference, fall back to the checkout-session
 * reference" rule cancel/refund/capture already uses.
 */
final readonly class SubscriptionReconciliationScanHandler implements JobHandler
{
    private const WINDOW_HOURS = 72;
    private const BATCH_SIZE = 100;
    private const RECURRENCE_MINUTES = 30;

    public function __construct(
        private SubscriptionRepository $subscriptions,
        private ResolveSubscriptionActionContext $context,
        private ReconciliationFindingRepository $findings,
        private ClockInterface $clock,
    ) {
    }

    public function type(): string
    {
        return 'subscription_reconciliation_scan';
    }

    public function recurrenceIntervalMinutes(): int
    {
        return self::RECURRENCE_MINUTES;
    }

    public function handle(Job $job): JobRunResult
    {
        $since = $this->clock->now()->modify('-' . self::WINDOW_HOURS . ' hours');
        $recent = $this->subscriptions->findRecentForReconciliation($since, self::BATCH_SIZE);

        $drifted = 0;
        $skipped = 0;
        foreach ($recent as $subscription) {
            $outcome = $this->checkOne($subscription);
            if ($outcome === null) {
                ++$skipped;
            } elseif ($outcome) {
                ++$drifted;
            }
        }

        return JobRunResult::success(['scanned' => \count($recent), 'drifted' => $drifted, 'skipped' => $skipped]);
    }

    /**
     * @return bool|null true if drift was found, false if it matched, null if it couldn't be checked
     */
    private function checkOne(Subscription $subscription): ?bool
    {
        $subscriptionId = $subscription->id();
        if ($subscriptionId === null) {
            return null;
        }

        $context = $this->context->forSubscription($subscription);
        if ($context === null || !$context->adapter instanceof SupportsSubscriptions) {
            return null;
        }

        try {
            $status = $context->adapter->getSubscriptionStatus($context->providerReference);
        } catch (ProviderAdapterException) {
            return null;
        }

        if ($status->mappedStatus === $subscription->status()->value) {
            return false;
        }

        $this->findings->save(ReconciliationFinding::detect(
            $subscription->clientId(),
            ReconciliationTargetType::Subscription,
            $subscriptionId,
            $subscription->status()->value,
            $status->rawStatus,
            $status->mappedStatus,
            $this->clock->now(),
        ));

        return true;
    }
}
