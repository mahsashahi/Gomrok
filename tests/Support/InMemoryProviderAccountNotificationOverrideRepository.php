<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Notifications\Domain\ProviderAccountNotificationOverride;
use Gomrok\Modules\Notifications\Domain\ProviderAccountNotificationOverrideRepository;

final class InMemoryProviderAccountNotificationOverrideRepository implements ProviderAccountNotificationOverrideRepository
{
    /** @var array<string, ProviderAccountNotificationOverride> keyed by "{accountId}:{purpose}" */
    private array $byKey = [];

    private int $nextId = 1;

    public function save(ProviderAccountNotificationOverride $override): void
    {
        if ($override->id() === null) {
            $override->assignId($this->nextId++);
        }
        $this->byKey["{$override->providerAccountId()}:{$override->purpose()}"] = $override;
    }

    public function remove(int $providerAccountId, string $purpose): void
    {
        unset($this->byKey["{$providerAccountId}:{$purpose}"]);
    }

    public function findActiveUrl(int $providerAccountId, string $purpose): ?string
    {
        $override = $this->byKey["{$providerAccountId}:{$purpose}"] ?? null;

        return $override !== null && $override->isActive() ? $override->url() : null;
    }

    public function forAccount(int $providerAccountId): array
    {
        return array_values(array_filter(
            $this->byKey,
            static fn (ProviderAccountNotificationOverride $o): bool => $o->providerAccountId() === $providerAccountId,
        ));
    }
}
