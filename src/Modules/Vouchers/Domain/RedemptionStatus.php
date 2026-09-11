<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

/**
 * A {@see VoucherRedemption}'s lifecycle state (Phase 17 Q2): `Reserved` counts
 * toward every usage cap until it becomes the terminal `Confirmed` (permanent)
 * or `Released` (frees the reservation).
 */
enum RedemptionStatus: string
{
    case Reserved = 'reserved';
    case Confirmed = 'confirmed';
    case Released = 'released';
}
