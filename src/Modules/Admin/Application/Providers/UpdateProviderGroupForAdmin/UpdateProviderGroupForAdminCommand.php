<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Providers\UpdateProviderGroupForAdmin;

/**
 * The Providers screen's "Edit routing group" modal (Phase 27) — composes
 * {@see \Gomrok\Modules\Providers\Application\ConfigureProviderGroup\ConfigureProviderGroupHandler}
 * and {@see \Gomrok\Modules\Providers\Application\ChangeProviderGroupStatus\ChangeProviderGroupStatusHandler}.
 */
final readonly class UpdateProviderGroupForAdminCommand
{
    /**
     * @param list<string> $countries
     * @param list<string> $purchaseTypes
     * @param list<string> $methods
     */
    public function __construct(
        public int $groupId,
        public string $name,
        public array $countries,
        public array $purchaseTypes,
        public array $methods,
        public ?string $currencyCode,
        public bool $active,
        public ?int $actorId = null,
    ) {
    }
}
