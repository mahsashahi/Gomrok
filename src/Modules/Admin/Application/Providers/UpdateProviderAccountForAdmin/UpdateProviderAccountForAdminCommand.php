<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Providers\UpdateProviderAccountForAdmin;

/**
 * The Providers screen's "Edit account" modal (Phase 27) — composes
 * {@see \Gomrok\Modules\Providers\Application\SetProviderAccountMarkets\SetProviderAccountMarketsHandler}
 * and {@see \Gomrok\Modules\Providers\Application\ChangeProviderAccountStatus\ChangeProviderAccountStatusHandler}.
 * Secret rotation is a separate, more sensitive action — not part of this
 * command.
 */
final readonly class UpdateProviderAccountForAdminCommand
{
    /**
     * @param list<string> $countries
     * @param list<string> $methods
     */
    public function __construct(
        public int $accountId,
        public string $name,
        public array $countries,
        public array $methods,
        public bool $active,
        public ?int $actorId = null,
    ) {
    }
}
