<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Sales;

use Gomrok\Modules\Admin\Application\PaymentProviderResolver;
use Gomrok\Modules\Payments\Application\PaymentDirectory;
use Gomrok\Modules\Payments\Application\PaymentSummary;
use Gomrok\Modules\Payments\Domain\PaymentAttempt;
use Gomrok\Modules\Payments\Domain\PaymentAttemptRepository;
use Gomrok\Modules\Payments\Domain\ProviderTransaction;
use Gomrok\Modules\Payments\Domain\ProviderTransactionRepository;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;

/**
 * Builds the admin panel's Sales screen (Phase 27) — every payment for a
 * client, status stat-tabs with real counts, and each row's expandable event
 * timeline (its {@see PaymentAttempt}s and their {@see ProviderTransaction}s,
 * flattened into one chronological list). Tabs are built from whatever
 * statuses actually occur in the client's data plus "All", rather than every
 * {@see \Gomrok\Modules\Payments\Domain\PaymentStatus} case — an empty tab
 * for a status nothing has ever reached would just be clutter.
 *
 * Timelines are eager-loaded for the current page only (bounded by
 * {@see self::LIMIT}), not the full result set — proportionate to an admin
 * screen's expected data volume (CLAUDE.md: "avoid overengineering"), not a
 * paginated/lazy API.
 */
final readonly class SalesListHandler
{
    private const LIMIT = 50;

    public function __construct(
        private PaymentDirectory $payments,
        private PaymentAttemptRepository $attempts,
        private ProviderTransactionRepository $transactions,
        private PaymentProviderResolver $providerResolver,
    ) {
    }

    public function forClient(int $clientId, ?string $statusFilter): SalesListResult
    {
        $all = $this->payments->forClient($clientId);
        usort($all, static fn (PaymentSummary $a, PaymentSummary $b): int => $b->createdAt <=> $a->createdAt);

        $tabs = $this->buildTabs($all, $statusFilter);

        $filtered = $statusFilter === null
            ? $all
            : array_values(array_filter($all, static fn (PaymentSummary $p): bool => $p->status === $statusFilter));

        $page = \array_slice($filtered, 0, self::LIMIT);
        $rows = array_map(fn (PaymentSummary $p): SalesPaymentRow => $this->toRow($p), $page);

        return new SalesListResult($tabs, $rows, \count($filtered));
    }

    /**
     * @param list<PaymentSummary> $all
     *
     * @return list<SalesStatusTab>
     */
    private function buildTabs(array $all, ?string $active): array
    {
        $counts = [];
        foreach ($all as $payment) {
            $counts[$payment->status] = ($counts[$payment->status] ?? 0) + 1;
        }

        $tabs = [new SalesStatusTab('all', 'All', \count($all), $active === null)];
        foreach ($counts as $status => $count) {
            $tabs[] = new SalesStatusTab($status, ucfirst(str_replace('_', ' ', $status)), $count, $active === $status);
        }

        return $tabs;
    }

    private function toRow(PaymentSummary $payment): SalesPaymentRow
    {
        return new SalesPaymentRow(
            $payment->id,
            Money::fromMinor($payment->amountMinor, Currency::of($payment->currencyCode))->format('en_US'),
            $payment->clientUserRef ?? '—',
            $this->providerResolver->nameFor($payment),
            $payment->paymentMethod !== null ? ucfirst($payment->paymentMethod) : null,
            ucfirst(str_replace('_', ' ', $payment->purchaseType)),
            ucfirst(str_replace('_', ' ', $payment->status)),
            $payment->createdAt,
            $this->timelineFor($payment->id),
        );
    }

    /**
     * @return list<SalesTimelineEntry>
     */
    private function timelineFor(int $paymentId): array
    {
        $entries = [];
        foreach ($this->attempts->forPayment($paymentId) as $attempt) {
            $attemptId = $attempt->id();
            if ($attemptId === null) {
                continue;
            }
            foreach ($this->transactions->forAttempt($attemptId) as $transaction) {
                $entries[] = new SalesTimelineEntry(
                    ucfirst(str_replace('_', ' ', $transaction->kind)),
                    $transaction->providerStatusRaw,
                    $transaction->createdAt->format('Y-m-d H:i:s'),
                );
            }
        }

        usort($entries, static fn (SalesTimelineEntry $a, SalesTimelineEntry $b): int => $a->createdAt <=> $b->createdAt);

        return $entries;
    }
}
