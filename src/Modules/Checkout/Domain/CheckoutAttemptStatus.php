<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Domain;

/**
 * The pre-payment lifecycle (Phase 18 Q3). The 9 "happy path" statuses carry a
 * fixed {@see rank()}; a transition is legal when the new rank is strictly
 * higher than the current one (skipping ranks is allowed — e.g. no voucher
 * skips `VoucherReserved`), when it repeats the current status (idempotent
 * no-op), or when it's one of the 4 {@see isExit()} statuses (reachable from
 * any non-terminal status). `ConvertedToPayment` additionally requires the
 * current status to be exactly `Confirmed`. Once a status is {@see isTerminal()}
 * (the 4 exits, or `ConvertedToPayment`), no further transition is allowed.
 *
 * Phase 18 only ever drives `Started` → `PricingResolved` → (`VoucherReserved`
 * →) `ProviderSelected`, the same-status no-op, and any non-terminal → exit.
 * `ProviderCheckoutCreated` … `Confirmed` / `ConvertedToPayment` are modelled
 * now (rank + guards) but have no real caller until providers (Phase 21+) and
 * Payments (Phase 20) exist; `Abandoned`'s automatic detection is Phase 29.
 */
enum CheckoutAttemptStatus: string
{
    case Started = 'started';
    case PricingResolved = 'pricing_resolved';
    case VoucherReserved = 'voucher_reserved';
    case ProviderSelected = 'provider_selected';
    case ProviderCheckoutCreated = 'provider_checkout_created';
    case RedirectedToProvider = 'redirected_to_provider';
    case ReturnedFromProvider = 'returned_from_provider';
    case Confirmed = 'confirmed';
    case ConvertedToPayment = 'converted_to_payment';
    case Failed = 'failed';
    case Canceled = 'canceled';
    case Expired = 'expired';
    case Abandoned = 'abandoned';

    /**
     * Happy-path rank, or `null` for the 4 exit statuses (they have no place in
     * the linear order — they're reachable from anywhere).
     */
    public function rank(): ?int
    {
        return match ($this) {
            self::Started => 1,
            self::PricingResolved => 2,
            self::VoucherReserved => 3,
            self::ProviderSelected => 4,
            self::ProviderCheckoutCreated => 5,
            self::RedirectedToProvider => 6,
            self::ReturnedFromProvider => 7,
            self::Confirmed => 8,
            self::ConvertedToPayment => 9,
            self::Failed, self::Canceled, self::Expired, self::Abandoned => null,
        };
    }

    public function isExit(): bool
    {
        return match ($this) {
            self::Failed, self::Canceled, self::Expired, self::Abandoned => true,
            default => false,
        };
    }

    public function isTerminal(): bool
    {
        return $this === self::ConvertedToPayment || $this->isExit();
    }
}
