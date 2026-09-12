<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

/**
 * The internal payment lifecycle (Phase 20 Q2). Unlike `CheckoutAttemptStatus`'s
 * linear happy-path, a payment's lifecycle genuinely branches (`paid` can move
 * to `refunded`, `partially_refunded`, or `disputed`; a dispute can resolve
 * back to `paid` or escalate to `chargeback`), so each status carries its own
 * explicit set of legal next-statuses via {@see allowedNextStatuses()} rather
 * than a single rank. Provider-specific statuses are mapped into these by each
 * adapter (Phase 21+) — an unmapped/unknown provider status is stored raw on
 * `provider_transactions.provider_status_raw` and never leaks in here.
 */
enum PaymentStatus: string
{
    case Created = 'created';
    case Pending = 'pending';
    case RequiresAction = 'requires_action';
    case Authorized = 'authorized';
    case Paid = 'paid';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';
    case Disputed = 'disputed';
    case Chargeback = 'chargeback';

    /**
     * @return list<self>
     */
    public function allowedNextStatuses(): array
    {
        return match ($this) {
            self::Created => [self::Pending, self::Canceled, self::Failed],
            self::Pending => [self::RequiresAction, self::Authorized, self::Paid, self::Failed, self::Canceled, self::Expired],
            self::RequiresAction => [self::Authorized, self::Paid, self::Failed, self::Canceled, self::Expired],
            self::Authorized => [self::Paid, self::Canceled, self::Expired, self::Failed],
            self::Paid => [self::Refunded, self::PartiallyRefunded, self::Disputed],
            self::PartiallyRefunded => [self::Refunded, self::Disputed],
            self::Disputed => [self::Chargeback, self::Paid],
            self::Refunded, self::Canceled, self::Expired, self::Failed, self::Chargeback => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedNextStatuses() === [];
    }
}
