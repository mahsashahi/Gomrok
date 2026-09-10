<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\DisableClient;

final readonly class DisableClientCommand
{
    public function __construct(
        public int $clientId,
        public ?string $reason = null,
        public ?int $disabledBy = null,
    ) {
    }
}
