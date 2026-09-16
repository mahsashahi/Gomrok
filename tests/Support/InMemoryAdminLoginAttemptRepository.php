<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Domain\AdminLoginAttempt;
use Gomrok\Modules\Admin\Domain\AdminLoginAttemptRepository;
use Gomrok\Modules\Admin\Domain\AdminUser;

final class InMemoryAdminLoginAttemptRepository implements AdminLoginAttemptRepository
{
    /** @var array<int, AdminLoginAttempt> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(AdminLoginAttempt $attempt): int
    {
        $id = $this->nextId++;
        $this->byId[$id] = $attempt;

        return $id;
    }

    public function countFailedSince(string $email, DateTimeImmutable $since): int
    {
        $normalised = AdminUser::normaliseEmail($email);

        return \count(array_filter(
            $this->byId,
            static fn (AdminLoginAttempt $a): bool => $a->email === $normalised && !$a->succeeded && $a->createdAt >= $since,
        ));
    }

    /**
     * @return list<AdminLoginAttempt>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }
}
