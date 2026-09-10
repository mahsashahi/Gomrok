<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\RotateProviderAccountSecret;

final readonly class RotateProviderAccountSecretCommand
{
    public function __construct(
        public int $accountId,
        public string $newSecretKey,
        public ?string $newPublicKey = null,
        public bool $replacePublicKey = false,
        public ?int $actorId = null,
    ) {
    }
}
