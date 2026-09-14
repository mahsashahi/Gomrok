<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Providers\Application\Adapter\PaymentProviderPort;
use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\Adapter\UnsupportedProviderType;
use RuntimeException;

/**
 * A {@see ProviderAdapterFactory} test double mapping a provider account id
 * to a pre-built adapter (typically a {@see FakePaymentProviderPort}), or to
 * one of the two real failure modes `for()` itself can throw.
 */
final class StubProviderAdapterFactory implements ProviderAdapterFactory
{
    /** @var array<int, PaymentProviderPort> */
    private array $adapters = [];

    /** @var array<int, string> */
    private array $unsupported = [];

    public function add(int $providerAccountId, PaymentProviderPort $adapter): self
    {
        $this->adapters[$providerAccountId] = $adapter;

        return $this;
    }

    public function withUnsupportedProviderType(int $providerAccountId, string $message): self
    {
        $this->unsupported[$providerAccountId] = $message;

        return $this;
    }

    public function for(int $providerAccountId): PaymentProviderPort
    {
        if (isset($this->unsupported[$providerAccountId])) {
            throw new UnsupportedProviderType($this->unsupported[$providerAccountId]);
        }

        if (!isset($this->adapters[$providerAccountId])) {
            throw new RuntimeException("No stub adapter configured for account {$providerAccountId}.");
        }

        return $this->adapters[$providerAccountId];
    }
}
