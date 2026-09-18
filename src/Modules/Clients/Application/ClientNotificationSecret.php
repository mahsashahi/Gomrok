<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application;

/**
 * The one path that reads a client's `notification_signing_secret` (Phase 6)
 * — used only by the Notifications module (Phase 28) to sign outbound
 * callbacks. Kept separate from {@see ClientDirectory} / {@see ClientSnapshot}
 * so the secret isn't handed out with every ordinary client read.
 */
interface ClientNotificationSecret
{
    /**
     * @return string|null the signing secret, or null if the client does not exist
     */
    public function secretFor(int $clientId): ?string;
}
