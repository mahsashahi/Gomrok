<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Application\LinkProviderCustomer;

final readonly class LinkProviderCustomerCommand
{
    public function __construct(
        public int $clientId,
        public int $providerAccountId,
        public string $clientUserRef,
        public string $providerCustomerId,
        public ?int $actorId = null,
    ) {
    }
}
