<?php

declare(strict_types=1);

namespace Gomrok\Modules\Subscriptions\Domain;

/**
 * The subscription lifecycle (Phase 26), per `Phases.md`'s named statuses:
 * `active`, `trialing`, `past_due`, `cancelled`. Like {@see \Gomrok\Modules\Payments\Domain\PaymentStatus},
 * this genuinely branches — `active` and `past_due` can cycle (a failed
 * renewal charge moves to `past_due`; a later successful retry moves back to
 * `active`) — so each status carries its own explicit
 * {@see self::allowedNextStatuses()} rather than a single rank.
 */
enum SubscriptionStatus: string
{
    case Active = 'active';
    case Trialing = 'trialing';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';

    /**
     * @return list<self>
     */
    public function allowedNextStatuses(): array
    {
        return match ($this) {
            self::Trialing => [self::Active, self::PastDue, self::Cancelled],
            self::Active => [self::PastDue, self::Cancelled],
            self::PastDue => [self::Active, self::Cancelled],
            self::Cancelled => [],
        };
    }

    public function isTerminal(): bool
    {
        return $this->allowedNextStatuses() === [];
    }
}
