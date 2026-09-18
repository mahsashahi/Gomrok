<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Application;

/**
 * Query parameters for {@see ClientNotificationDirectory::search()} /
 * {@see ClientNotificationDirectory::countMatching()}. Every field is
 * optional — an unset field is unrestricted.
 */
final readonly class ClientNotificationFilter
{
    public function __construct(
        public ?int $clientId = null,
        public ?string $status = null,
        public ?string $purpose = null,
        public int $limit = 50,
        public int $offset = 0,
    ) {
    }
}
