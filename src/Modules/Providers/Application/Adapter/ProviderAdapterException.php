<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

use RuntimeException;

/**
 * Base type for everything a provider adapter throws (Phase 21 Q2): adapters
 * are Infrastructure, and a transport/provider-level fault is an infra fault
 * per the Phase 3 Q3 error model — it throws, it does not return `Result`. A
 * calling Application handler (Phase 24+) catches this and decides
 * retry-with-backoff vs. dead-letter.
 */
class ProviderAdapterException extends RuntimeException
{
}
