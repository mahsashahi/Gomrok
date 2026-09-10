<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application;

/**
 * Read-only view of a provider type for other modules (routing — Phase 10) and
 * the admin panel (Phase 27). Capabilities / purchase types are the type-level
 * declaration as string values.
 */
final readonly class ProviderTypeSummary
{
    /**
     * @param list<string> $purchaseTypes
     * @param list<string> $capabilities
     */
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public bool $requiresRegistration,
        public bool $apiCapable,
        public array $purchaseTypes,
        public array $capabilities,
    ) {
    }
}
