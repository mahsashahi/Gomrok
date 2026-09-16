<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\CreateAdminUser;

final readonly class CreateAdminUserResult
{
    public function __construct(public int $adminUserId)
    {
    }
}
