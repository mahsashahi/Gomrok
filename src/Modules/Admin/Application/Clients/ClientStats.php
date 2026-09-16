<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Clients;

/**
 * The Clients screen's three stat tabs (Phases.md scope: "Clients (stat tabs +
 * New client modal)") — counts driving the `All` / `Live` / `Disabled` filter
 * pills. `live` counts by derived {@see ClientsScreenHandler} environment
 * (an active `gk_live_…` key present), not a client-level field — the domain
 * has no such field; only individual API keys carry a mode.
 */
final readonly class ClientStats
{
    public function __construct(
        public int $all,
        public int $live,
        public int $disabled,
    ) {
    }
}
