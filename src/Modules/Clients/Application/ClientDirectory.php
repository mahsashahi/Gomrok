<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application;

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
}
