<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * The result of {@see SupportsSubscriptions::getSubscriptionStatus()}.
 * `mappedStatus` is a **provisional**, normalized string, not a typed enum —
 * the `Subscriptions` module and its own status vocabulary don't exist until
 * Phase 26; this method's return type is expected to change to a real
 * `SubscriptionStatus` enum then. `rawStatus` and `mappedStatus` happen to be
 * identical today (the mapping is currently a pass-through normalization of
 * the provider's own vocabulary) — kept as two fields regardless, so callers
 * don't have to change when Phase 26 makes the mapping non-trivial.
 */
final readonly class ProviderSubscriptionStatus
{
    public function __construct(
        public string $providerReference,
        public string $rawStatus,
        public string $mappedStatus,
    ) {
    }
}
