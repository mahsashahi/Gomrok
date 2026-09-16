<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application;

use Gomrok\Modules\Clients\Domain\EndpointPurpose;

/**
 * The Clients module's published read API. Other modules resolve a client
 * through this and get a {@see ClientSnapshot} back — they never see the
 * aggregate or the repository.
 */
interface ClientDirectory
{
    public function findById(int $id): ?ClientSnapshot;

    public function findBySlug(string $slug): ?ClientSnapshot;

    public function existsById(int $id): bool;

    /**
     * Every client, for the admin panel's client switcher (Phase 27) — the
     * only caller that needs the whole list rather than one resolved client.
     *
     * @return list<ClientSnapshot>
     */
    public function all(): array;

    /**
     * The client's active callback URL for one purpose (Phase 24 Q3), or
     * `null` if none is configured — just the URL, not the full
     * `ClientEndpoint` aggregate member, since that's all a cross-module
     * caller needs.
     */
    public function findActiveEndpointUrl(int $clientId, EndpointPurpose $purpose): ?string;
}
