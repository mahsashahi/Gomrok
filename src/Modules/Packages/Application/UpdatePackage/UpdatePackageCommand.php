<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\UpdatePackage;

/**
 * Edit a package's catalogue identity and display fields. `null` fields are left
 * unchanged; the `clear*` flags explicitly null them. Availability, purchase
 * capabilities and status are separate use cases.
 */
final readonly class UpdatePackageCommand
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public int $packageId,
        public ?string $name = null,
        public ?string $description = null,
        public ?array $metadata = null,
        public bool $clearDescription = false,
        public bool $clearMetadata = false,
        public ?string $badge = null,
        public ?bool $highlighted = null,
        public ?string $clientPackageId = null,
        public bool $clearBadge = false,
        public bool $clearClientPackageId = false,
        public ?int $actorId = null,
    ) {
    }
}
