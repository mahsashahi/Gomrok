<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Admin\Domain\AdminSession;
use Gomrok\Modules\Admin\Domain\AdminSessionRepository;

final class InMemoryAdminSessionRepository implements AdminSessionRepository
{
    /** @var array<int, AdminSession> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(AdminSession $session): void
    {
        if ($session->id() === null) {
            $session->assignId($this->nextId++);
        }
        $id = $session->id();
        \assert($id !== null);
        $this->byId[$id] = $session;
    }

    public function findByTokenHash(string $tokenHash): ?AdminSession
    {
        foreach ($this->byId as $session) {
            if ($session->tokenHash() === $tokenHash) {
                return $session;
            }
        }

        return null;
    }
}
