<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\CreatePackage;

/**
 * Add a package to a client's catalogue. `code` is unique per client. Market
 * availability is set separately (`SetPackageAvailability`) — a new package is
 * available everywhere until restricted (Phase 11 Q2).
 */
final readonly class CreatePackageCommand
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public int $clientId,
        public string $code,
        public string $name,
        public ?string $description = null,
        public ?array $metadata = null,
        public ?int $actorId = null,
    ) {
    }
}
