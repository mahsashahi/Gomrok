<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Dashboard;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\PaymentProviderResolver;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Payments\Application\PaymentDirectory;
use Gomrok\Modules\Payments\Application\PaymentSummary;
use Gomrok\Modules\Subscriptions\Application\SubscriptionDirectory;
use Gomrok\Modules\Subscriptions\Application\SubscriptionSummary;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;
use Psr\Clock\ClockInterface;

/**
 * Builds the admin panel's Home screen (Phase 27) — real read-only
 * composition across Payments/Subscriptions/Providers, no fabricated
 * numbers. Deliberately simple aggregation (loading a client's full payment/
 * subscription list and filtering/summing in PHP, no new SQL aggregate
 * queries) — proportionate to an admin dashboard's expected data volume,
 * matching CLAUDE.md's "avoid overengineering."
 *
 * Two known simplifications, both documented rather than silently wrong:
 * revenue only sums payments already in the client's own default currency
 * (no cross-currency conversion here); a subscription-renewal payment (no
 * `checkout_attempt_id`, Phase 26 Q2) shows no provider in "Recent payments"
 * — resolving one would mean following `subscription_payment_links`, not
 * built this increment.
 */
final readonly class HomeDashboardHandler
{
    /** period key => number of days it spans. */
    private const PERIODS = ['today' => 1, '7d' => 7, '30d' => 30];

    private const SPARKLINE_HOURS = 24;
    private const SPARKLINE_MAX_HEIGHT_PX = 70;
    private const SPARKLINE_MIN_HEIGHT_PX = 4;
    private const RECENT_PAYMENTS_LIMIT = 5;

    public function __construct(
        private ClientDirectory $clients,
        private PaymentDirectory $payments,
        private SubscriptionDirectory $subscriptions,
        private PaymentProviderResolver $providerResolver,
        private ClockInterface $clock,
    ) {
    }

    public function forClient(int $clientId, string $period): HomeDashboardResult
    {
        $period = \array_key_exists($period, self::PERIODS) ? $period : 'today';
        $client = $this->clients->findById($clientId);
        $currencyCode = $client !== null ? $client->defaultCurrency : 'USD';

        $now = $this->clock->now();
        $startOfToday = $now->setTime(0, 0, 0);

        $allPayments = $this->payments->forClient($clientId);
        $allSubscriptions = $this->subscriptions->forClient($clientId);

        $todayPayments = $this->since($allPayments, $startOfToday);
        $successfulToday = \count($this->withStatus($todayPayments, 'paid'));
        $failedToday = \count($this->withStatus($todayPayments, 'failed'));
        $activeSubscriptions = \count(array_filter(
            $allSubscriptions,
            static fn (SubscriptionSummary $s): bool => \in_array($s->status, ['active', 'trialing'], true),
        ));

        $days = self::PERIODS[$period];
        $periodStart = $startOfToday->modify(\sprintf('-%d days', $days - 1));
        $previousStart = $periodStart->modify(\sprintf('-%d days', $days));

        $currentPayments = $this->paymentsInRange($allPayments, $periodStart, null);
        $previousPayments = $this->paymentsInRange($allPayments, $previousStart, $periodStart);
        $currentSubscriptions = $this->subscriptionsInRange($allSubscriptions, $periodStart, null);
        $previousSubscriptions = $this->subscriptionsInRange($allSubscriptions, $previousStart, $periodStart);

        return new HomeDashboardResult(
            $successfulToday,
            $failedToday,
            $activeSubscriptions,
            $this->sparkline($todayPayments, $now),
            $period,
            [
                $this->revenueStat($currentPayments, $previousPayments, $currencyCode),
                $this->countStat('Successful payments', \count($this->withStatus($currentPayments, 'paid')), \count($this->withStatus($previousPayments, 'paid'))),
                $this->countStat('Failed payments', \count($this->withStatus($currentPayments, 'failed')), \count($this->withStatus($previousPayments, 'failed'))),
                $this->countStat('New subscriptions', \count($currentSubscriptions), \count($previousSubscriptions)),
            ],
            $this->recentPayments($allPayments),
        );
    }

    /**
     * @param list<PaymentSummary> $payments
     *
     * @return list<PaymentSummary>
     */
    private function since(array $payments, DateTimeImmutable $since): array
    {
        return array_values(array_filter(
            $payments,
            static fn (PaymentSummary $p): bool => new DateTimeImmutable($p->createdAt) >= $since,
        ));
    }

    /**
     * @param list<PaymentSummary> $payments
     *
     * @return list<PaymentSummary>
     */
    private function paymentsInRange(array $payments, DateTimeImmutable $start, ?DateTimeImmutable $end): array
    {
        return array_values(array_filter(
            $payments,
            static fn (PaymentSummary $p): bool => self::inRange(new DateTimeImmutable($p->createdAt), $start, $end),
        ));
    }

    /**
     * @param list<SubscriptionSummary> $subscriptions
     *
     * @return list<SubscriptionSummary>
     */
    private function subscriptionsInRange(array $subscriptions, DateTimeImmutable $start, ?DateTimeImmutable $end): array
    {
        return array_values(array_filter(
            $subscriptions,
            static fn (SubscriptionSummary $s): bool => self::inRange(new DateTimeImmutable($s->createdAt), $start, $end),
        ));
    }

    private static function inRange(DateTimeImmutable $createdAt, DateTimeImmutable $start, ?DateTimeImmutable $end): bool
    {
        return $createdAt >= $start && ($end === null || $createdAt < $end);
    }

    /**
     * @param list<PaymentSummary> $payments
     *
     * @return list<PaymentSummary>
     */
    private function withStatus(array $payments, string $status): array
    {
        return array_values(array_filter($payments, static fn (PaymentSummary $p): bool => $p->status === $status));
    }

    /**
     * @param list<PaymentSummary> $todayPayments
     *
     * @return list<int>
     */
    private function sparkline(array $todayPayments, DateTimeImmutable $now): array
    {
        $byHour = array_fill(0, self::SPARKLINE_HOURS, 0);
        foreach ($this->withStatus($todayPayments, 'paid') as $payment) {
            $hour = (int) (new DateTimeImmutable($payment->createdAt))->format('G');
            ++$byHour[$hour];
        }

        $max = max(1, ...$byHour);
        $currentHour = (int) $now->format('G');

        return array_map(
            static fn (int $hour, int $count): int => $hour > $currentHour
                ? 0
                : max(self::SPARKLINE_MIN_HEIGHT_PX, (int) round($count / $max * self::SPARKLINE_MAX_HEIGHT_PX)),
            array_keys($byHour),
            $byHour,
        );
    }

    /**
     * @param list<PaymentSummary> $current
     * @param list<PaymentSummary> $previous
     */
    private function revenueStat(array $current, array $previous, string $currencyCode): HomeOverviewStat
    {
        $currency = Currency::of($currencyCode);
        $currentTotal = $this->sumPaid($current, $currency);
        $previousTotal = $this->sumPaid($previous, $currency);

        return new HomeOverviewStat(
            'Revenue',
            $currentTotal->format('en_US'),
            ...$this->delta($currentTotal->toMinor(), $previousTotal->toMinor()),
        );
    }

    /**
     * @param list<PaymentSummary> $payments
     */
    private function sumPaid(array $payments, Currency $currency): Money
    {
        $total = Money::zero($currency);
        foreach ($this->withStatus($payments, 'paid') as $payment) {
            if (strtoupper($payment->currencyCode) !== $currency->code()) {
                continue;
            }
            $total = $total->plus(Money::fromMinor($payment->amountMinor, $currency));
        }

        return $total;
    }

    private function countStat(string $label, int $current, int $previous): HomeOverviewStat
    {
        return new HomeOverviewStat($label, (string) $current, ...$this->delta($current, $previous));
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    private function delta(int $current, int $previous): array
    {
        if ($previous === 0) {
            return $current > 0 ? ['New', true] : [null, true];
        }

        $percent = ($current - $previous) / $previous * 100;

        return [\sprintf('%+.0f%%', $percent), $percent >= 0];
    }

    /**
     * @param list<PaymentSummary> $payments
     *
     * @return list<RecentPaymentRow>
     */
    private function recentPayments(array $payments): array
    {
        $sorted = $payments;
        usort($sorted, static fn (PaymentSummary $a, PaymentSummary $b): int => $b->createdAt <=> $a->createdAt);

        return array_map(
            fn (PaymentSummary $p): RecentPaymentRow => new RecentPaymentRow(
                Money::fromMinor($p->amountMinor, Currency::of($p->currencyCode))->format('en_US'),
                $p->clientUserRef ?? '—',
                $this->providerResolver->nameFor($p),
                ucfirst(str_replace('_', ' ', $p->status)),
                (new DateTimeImmutable($p->createdAt))->format('Y-m-d H:i'),
            ),
            \array_slice($sorted, 0, self::RECENT_PAYMENTS_LIMIT),
        );
    }
}
