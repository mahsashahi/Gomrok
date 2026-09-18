<?php

declare(strict_types=1);

namespace Gomrok\Modules\Reconciliation\Application\Jobs;

use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentAttemptRepository;
use Gomrok\Modules\Payments\Domain\PaymentRepository;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterException;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\Adapter\UnsupportedProviderType;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFinding;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFindingRepository;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationTargetType;
use Gomrok\Shared\Application\Jobs\JobHandler;
use Gomrok\Shared\Application\Jobs\JobRunResult;
use Gomrok\Shared\Domain\Jobs\Job;
use Psr\Clock\ClockInterface;

/**
 * Polls each recently-touched payment's real provider status and records a
 * {@see ReconciliationFinding} when it disagrees with Gomrok's local status
 * — catching a missed or lost webhook, not just a payment stuck mid-flow.
 * One bad provider call is caught per-item, never aborting the rest of the
 * batch — unlike a single-item job, this one processes many independent
 * payments per run.
 */
final readonly class PaymentReconciliationScanHandler implements JobHandler
{
    private const WINDOW_HOURS = 72;
    private const BATCH_SIZE = 100;
    private const RECURRENCE_MINUTES = 30;

    /**
     * @var list<GatewayReferenceType>
     */
    private const REFERENCE_LOOKUP_ORDER = [
        GatewayReferenceType::PaymentIntent,
        GatewayReferenceType::CheckoutSession,
        GatewayReferenceType::Order,
        GatewayReferenceType::Transaction,
    ];

    public function __construct(
        private PaymentRepository $payments,
        private PaymentAttemptRepository $paymentAttempts,
        private GatewayReferenceRepository $gatewayReferences,
        private ProviderAdapterFactory $adapterFactory,
        private ReconciliationFindingRepository $findings,
        private ClockInterface $clock,
    ) {
    }

    public function type(): string
    {
        return 'payment_reconciliation_scan';
    }

    public function recurrenceIntervalMinutes(): int
    {
        return self::RECURRENCE_MINUTES;
    }

    public function handle(Job $job): JobRunResult
    {
        $since = $this->clock->now()->modify('-' . self::WINDOW_HOURS . ' hours');
        $recent = $this->payments->findRecentForReconciliation($since, self::BATCH_SIZE);

        $drifted = 0;
        $skipped = 0;
        foreach ($recent as $payment) {
            $outcome = $this->checkOne($payment);
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
    private function checkOne(Payment $payment): ?bool
    {
        $paymentId = $payment->id();
        if ($paymentId === null) {
            return null;
        }

        $providerAccountId = $this->paymentAttempts->findLatestForPayment($paymentId)?->providerAccountId();
        if ($providerAccountId === null) {
            return null;
        }

        $reference = $this->resolveReference($paymentId);
        if ($reference === null) {
            return null;
        }

        try {
            $adapter = $this->adapterFactory->for($providerAccountId);
            $status = $adapter->getPaymentStatus($reference);
        } catch (UnsupportedProviderType|ProviderAdapterException) {
            return null;
        }

        if ($status->mappedStatus === $payment->status()) {
            return false;
        }

        $this->findings->save(ReconciliationFinding::detect(
            $payment->clientId(),
            ReconciliationTargetType::Payment,
            $paymentId,
            $payment->status()->value,
            $status->rawStatus,
            $status->mappedStatus->value,
            $this->clock->now(),
        ));

        return true;
    }

    private function resolveReference(int $paymentId): ?string
    {
        $byType = [];
        foreach ($this->gatewayReferences->forPayment($paymentId) as $reference) {
            $byType[$reference->referenceType->value] = $reference->referenceValue;
        }

        foreach (self::REFERENCE_LOOKUP_ORDER as $type) {
            if (isset($byType[$type->value])) {
                return $byType[$type->value];
            }
        }

        return null;
    }
}
