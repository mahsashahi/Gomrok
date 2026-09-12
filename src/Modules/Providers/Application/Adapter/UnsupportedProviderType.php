<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * {@see ProviderAdapterFactory::for()} was asked for a provider account whose
 * provider type has no adapter implementation yet (Mollie/PayPal — Phase 22,
 * Ziraat — Phase 23).
 */
final class UnsupportedProviderType extends ProviderAdapterException
{
}
