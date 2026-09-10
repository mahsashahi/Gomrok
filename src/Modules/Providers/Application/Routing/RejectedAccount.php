<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Routing;

/**
 * A provider account the router considered and dropped, with the reason.
 */
final readonly class RejectedAccount
{
    public function __construct(
        public int $accountId,
        public string $slug,
        public RejectionReason $reason,
    ) {
    }
}
