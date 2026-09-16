<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Domain;

use DateTimeImmutable;

/**
 * One login attempt, successful or not (CLAUDE.md: "add login attempt
 * logging"). `email` is recorded even when it matches no {@see AdminUser} —
 * an enumeration pattern against unknown addresses stays visible. Write-once.
 */
final readonly class AdminLoginAttempt
{
    public function __construct(
        public ?int $id,
        public string $email,
        public ?int $adminUserId,
        public string $ip,
        public bool $succeeded,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public static function record(string $email, ?int $adminUserId, string $ip, bool $succeeded, DateTimeImmutable $now): self
    {
        return new self(null, AdminUser::normaliseEmail($email), $adminUserId, $ip, $succeeded, $now);
    }
}
