<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Infrastructure;

use DateTimeImmutable;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionPaymentLink;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionPaymentLinkRepository;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

final readonly class PdoSubscriptionPaymentLinkRepository implements SubscriptionPaymentLinkRepository
{
    private const DT = 'Y-m-d H:i:s';

    public function __construct(private PDO $pdo)
    {
    }

    public function save(SubscriptionPaymentLink $link): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO subscription_payment_links (subscription_id, payment_id, billing_period_start, billing_period_end, created_at)
             VALUES (:subscription_id, :payment_id, :billing_period_start, :billing_period_end, :now)',
        );
        $statement->execute([
            'subscription_id' => $link->subscriptionId,
            'payment_id' => $link->paymentId,
            'billing_period_start' => $link->billingPeriodStart?->format(self::DT),
            'billing_period_end' => $link->billingPeriodEnd?->format(self::DT),
            'now' => $link->createdAt->format(self::DT),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function forSubscription(int $subscriptionId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM subscription_payment_links WHERE subscription_id = :s ORDER BY id ASC');
        $statement->execute(['s' => $subscriptionId]);

        $links = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $links[] = $this->hydrate($row);
            }
        }

        return $links;
    }

    public function findByPaymentId(int $paymentId): ?SubscriptionPaymentLink
    {
        $statement = $this->pdo->prepare('SELECT * FROM subscription_payment_links WHERE payment_id = :p');
        $statement->execute(['p' => $paymentId]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->hydrate($row) : null;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function hydrate(array $row): SubscriptionPaymentLink
    {
        $start = Row::nullableStr($row['billing_period_start'] ?? null);
        $end = Row::nullableStr($row['billing_period_end'] ?? null);

        return new SubscriptionPaymentLink(
            Row::int($row['id'] ?? null),
            Row::int($row['subscription_id'] ?? null),
            Row::int($row['payment_id'] ?? null),
            $start !== null ? new DateTimeImmutable($start) : null,
            $end !== null ? new DateTimeImmutable($end) : null,
            new DateTimeImmutable(Row::str($row['created_at'] ?? 'now')),
        );
    }
}
