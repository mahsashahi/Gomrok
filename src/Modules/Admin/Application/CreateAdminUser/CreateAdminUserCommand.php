<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\CreateAdminUser;

/**
 * Create a new admin panel user. `role` is a raw {@see \Gomrok\Modules\Admin\Domain\AdminRole}
 * value, validated by the handler — CLAUDE.md: "only these two roles are
 * required for now." Unlike a client API key, the initial password is chosen
 * by the admin creating the account (typed here, not generated), since a
 * password authenticates a person, not a machine — there is nothing to
 * "reveal once."
 */
final readonly class CreateAdminUserCommand
{
    public function __construct(
        public string $name,
        public string $email,
        public string $password,
        public string $role,
        public ?int $actorId = null,
    ) {
    }
}
