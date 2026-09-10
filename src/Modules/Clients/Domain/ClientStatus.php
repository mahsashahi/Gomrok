<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

/**
 * Client lifecycle (Phase 6 Q4) — a soft, reversible pair. `disabled` is
 * enforced at the Phase 7 auth chokepoint; it does not touch the client's API
 * keys.
 */
enum ClientStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
