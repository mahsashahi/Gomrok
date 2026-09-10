<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application;

use Gomrok\Modules\Clients\Domain\Client;
use Gomrok\Modules\Clients\Domain\ClientStatus;

/**
 * A read-only view of a client for other modules. They depend on this DTO and
 * {@see ClientDirectory}, never on the {@see Client} aggregate or its
 * repository. Carries no secrets.
 */
final readonly class ClientSnapshot
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $name,
        public ClientStatus $status,
        public string $defaultCurrency,
        public ?string $defaultCountry,
        public string $timezone,
    ) {
    }

    public static function fromClient(Client $client): self
    {
        $id = $client->id();
        if ($id === null) {
            throw new \LogicException('Cannot snapshot an unpersisted client.');
        }

        return new self(
            $id,
            (string) $client->slug(),
            $client->name(),
            $client->status(),
            $client->defaultCurrency()->code(),
            $client->defaultCountry()?->value,
            $client->timezone(),
        );
    }

    public function isActive(): bool
    {
        return $this->status === ClientStatus::Active;
    }
}
