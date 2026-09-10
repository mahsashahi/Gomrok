<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

use DateTimeImmutable;

/**
 * Persistence port for {@see ClientApiKey}. Kept separate from
 * {@see ClientRepository} — keys have their own lifecycle and are looked up on
 * the hot auth path by `key_id`.
 */
interface ClientApiKeyRepository
{
    public function save(ClientApiKey $apiKey): void;

    public function findByKeyId(string $keyId): ?ClientApiKey;

    /**
     * Stamp `last_used_at` for one key. Called on the auth hot path, throttled
     * by the caller (Phase 7 Q3) — a single targeted `UPDATE`, no aggregate load.
     */
    public function touchLastUsed(int $id, DateTimeImmutable $at): void;

    /**
     * @return list<ClientApiKey>
     */
    public function findByClientId(int $clientId): array;

    public function countActiveForClient(int $clientId): int;
}
