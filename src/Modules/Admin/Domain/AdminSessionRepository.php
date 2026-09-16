<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Domain;

interface AdminSessionRepository
{
    public function save(AdminSession $session): void;

    public function findByTokenHash(string $tokenHash): ?AdminSession;
}
