<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Domain;

use DateTimeImmutable;

interface AdminLoginAttemptRepository
{
    /**
     * @return int the new row's id
     */
    public function save(AdminLoginAttempt $attempt): int;

    /**
     * Count of failed attempts for `$email` since `$since` — the lockout
     * check reads this.
     */
    public function countFailedSince(string $email, DateTimeImmutable $since): int;
}
