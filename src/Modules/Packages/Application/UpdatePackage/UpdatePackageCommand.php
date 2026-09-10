<?php

declare(strict_types=1);

namespace Gomrok\Modules\Packages\Application\UpdatePackage;

/**
 * Edit a package's catalogue identity. `null` fields are left unchanged;
 * `clearDescription` / `clearMetadata` explicitly null them. Availability and
 * status are separate use cases.
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
        public ?int $actorId = null,
    ) {
    }
}
