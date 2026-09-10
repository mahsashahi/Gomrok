<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Domain\ApiKeyStatus;
use Gomrok\Modules\Clients\Domain\ClientApiKey;
use Gomrok\Modules\Clients\Domain\ClientApiKeyRepository;

final class InMemoryClientApiKeyRepository implements ClientApiKeyRepository
{
    /** @var array<int, ClientApiKey> */
    private array $byId = [];

    /** @var list<int> ids passed to touchLastUsed(), in order */
    public array $touched = [];

    private int $nextId = 1;

    public function save(ClientApiKey $apiKey): void
    {
        if ($apiKey->id() === null) {
            $apiKey->assignId($this->nextId++);
        }

        $id = $apiKey->id();
        \assert($id !== null);
        $this->byId[$id] = $apiKey;
    }

    public function findByKeyId(string $keyId): ?ClientApiKey
    {
        foreach ($this->byId as $key) {
            if ($key->keyId() === $keyId) {
                return $key;
            }
        }

        return null;
    }

    public function touchLastUsed(int $id, DateTimeImmutable $at): void
    {
        $this->touched[] = $id;
        if (isset($this->byId[$id])) {
            $this->byId[$id]->markUsed($at);
        }
    }

    public function findByClientId(int $clientId): array
    {
        return array_values(array_filter(
            $this->byId,
            static fn (ClientApiKey $key): bool => $key->clientId() === $clientId,
        ));
    }

    public function countActiveForClient(int $clientId): int
    {
        return \count(array_filter(
            $this->byId,
            static fn (ClientApiKey $key): bool => $key->clientId() === $clientId && $key->status() === ApiKeyStatus::Active,
        ));
    }
}
