<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application;

use Gomrok\Modules\Payments\Application\PaymentSummary;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\Routing\ProviderRoutingDecisionSnapshotRepository;

/**
 * Resolves a payment's provider account name for admin panel screens
 * (Phase 27) — shared by the Home and Sales screens. A subscription-renewal
 * payment (no `checkout_attempt_id`, Phase 26 Q2) has no routing snapshot to
 * resolve from, so it reports `'—'` rather than following
 * `subscription_payment_links`, which isn't built yet.
 */
final readonly class PaymentProviderResolver
{
    public function __construct(
        private ProviderRoutingDecisionSnapshotRepository $routingSnapshots,
        private ProviderAccountDirectory $providerAccounts,
    ) {
    }

    public function nameFor(PaymentSummary $payment): string
    {
        if ($payment->checkoutAttemptId === null) {
            return '—';
        }

        $routing = $this->routingSnapshots->findByCheckoutAttemptId($payment->checkoutAttemptId);
        if ($routing === null) {
            return '—';
        }

        $account = $this->providerAccounts->findById($routing->providerAccountId);

        return $account !== null ? $account->name : '—';
    }
}
