<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * Coarse grouping of {@see Capability} flags — for admin-panel display and
 * reporting, not for routing logic.
 */
enum CapabilityGroup: string
{
    case Payment = 'payment';
    case Refund = 'refund';
    case Subscription = 'subscription';
    case Security = 'security';
    case Operational = 'operational';
}
