<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Routing;

/**
 * A provider account that survived routing, in priority order. The first entry
 * of a {@see RoutingDecision} is the chosen one; the rest are the fallback
 * chain a payment attempt walks on a provider failure.
 */
final readonly class RoutedAccount
{
    public function __construct(
        public int $accountId,
        public string $slug,
        public string $providerTypeCode,
        public string $mode,
        public int $priority,
    ) {
    }
}
