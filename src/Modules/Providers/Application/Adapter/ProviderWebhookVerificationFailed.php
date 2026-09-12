<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * {@see PaymentProviderPort::parseWebhook()} refused to parse a webhook whose
 * signature does not verify — the Webhooks module (Phase 25) must not act on
 * its payload.
 */
final class ProviderWebhookVerificationFailed extends ProviderAdapterException
{
}
