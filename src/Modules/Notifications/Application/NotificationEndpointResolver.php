<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Application;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Clients\Domain\EndpointPurpose;
use Gomrok\Modules\Notifications\Domain\ProviderAccountNotificationOverrideRepository;

/**
 * Resolves the URL a notification for `(clientId, purpose)` actually goes
 * to (Phase 28 Q1, user-specified hybrid): a provider account's own active
 * override for that purpose wins when one exists and the event names an
 * account; otherwise the client's `client_endpoints` default. Neither
 * configured → `null`, meaning "nowhere to send it" (a client-configuration
 * gap, not a Gomrok failure — the caller skips enqueuing rather than erroring).
 */
final readonly class NotificationEndpointResolver
{
    public function __construct(
        private ClientDirectory $clients,
        private ProviderAccountNotificationOverrideRepository $overrides,
    ) {
    }

    public function resolve(int $clientId, ?int $providerAccountId, EndpointPurpose $purpose): ?string
    {
        if ($providerAccountId !== null) {
            $override = $this->overrides->findActiveUrl($providerAccountId, $purpose->value);
            if ($override !== null) {
                return $override;
            }
        }

        return $this->clients->findActiveEndpointUrl($clientId, $purpose);
    }
}
