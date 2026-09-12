<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * The provider's API rejected the request, or the call failed in transit
 * (timeout, 5xx, malformed response) — never thrown for a request that
 * merely used an unsupported capability (that's prevented at the type level,
 * Phase 1 Q5).
 */
final class ProviderRequestFailed extends ProviderAdapterException
{
}
