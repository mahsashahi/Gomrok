<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * The provider account's secret was rejected — almost always a
 * misconfigured/rotated/revoked credential, not a retryable transient fault.
 */
final class ProviderAuthenticationFailed extends ProviderAdapterException
{
}
