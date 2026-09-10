<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * An immutable set of {@see Capability}. This is the value the application layer
 * checks before attempting a provider action — per `Architecture.md` §8 the
 * "resolved" set is the intersection of the provider type's declared caps, the
 * account config (Phase 9), and the client/country config (Phase 10). Phase 8
 * produces the type-level set (optionally narrowed by payment method).
 */
final readonly class ProviderCapabilities
{
    /** @var array<string, Capability> keyed by capability value */
    private array $byValue;

    /**
     * @param iterable<Capability> $capabilities
     */
    public function __construct(iterable $capabilities)
    {
        $byValue = [];
        foreach ($capabilities as $capability) {
            $byValue[$capability->value] = $capability;
        }
        $this->byValue = $byValue;
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(Capability ...$capabilities): self
    {
        return new self($capabilities);
    }

    public function has(Capability $capability): bool
    {
        return isset($this->byValue[$capability->value]);
    }

    /**
     * @return list<Capability> in `Capability` declaration order
     */
    public function all(): array
    {
        return array_values(array_filter(
            Capability::cases(),
            fn (Capability $c): bool => isset($this->byValue[$c->value]),
        ));
    }

    public function without(Capability ...$capabilities): self
    {
        $remaining = $this->byValue;
        foreach ($capabilities as $capability) {
            unset($remaining[$capability->value]);
        }

        return new self(array_values($remaining));
    }

    public function intersect(self $other): self
    {
        return new self(array_values(array_intersect_key($this->byValue, $other->byValue)));
    }

    public function equals(self $other): bool
    {
        return $this->all() === $other->all();
    }

    public function count(): int
    {
        return \count($this->byValue);
    }

    public function isEmpty(): bool
    {
        return $this->byValue === [];
    }
}
