<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

/**
 * Persistence port for the {@see Client} aggregate (endpoints included). The
 * MySQL adapter lives in the module's `Infrastructure` layer.
 */
interface ClientRepository
{
    /**
     * Insert a new client (assigning its `id`) or update an existing one,
     * synchronising its `client_endpoints` rows.
     */
    public function save(Client $client): void;

    public function findById(int $id): ?Client;

    public function findBySlug(string $slug): ?Client;

    public function existsWithSlug(string $slug): bool;
}
