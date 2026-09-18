<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Domain;

/**
 * Persistence port for {@see ProviderAccountNotificationOverride}.
 */
interface ProviderAccountNotificationOverrideRepository
{
    public function save(ProviderAccountNotificationOverride $override): void;

    public function remove(int $providerAccountId, string $purpose): void;

    public function findActiveUrl(int $providerAccountId, string $purpose): ?string;

    /**
     * @return list<ProviderAccountNotificationOverride>
     */
    public function forAccount(int $providerAccountId): array;
}
