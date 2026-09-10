<?php

declare(strict_types=1);

namespace Gomrok\Shared\Domain;

use LogicException;

/**
 * The outcome of an application use case: either a value (`ok`) or a
 * {@see DomainError} (`err`). Expected failures are values, not exceptions.
 *
 * Deliberately non-generic — a use case documents its own success type in its
 * own return phpdoc (`@return Result` + `@phpstan-return` where it helps).
 */
final readonly class Result
{
    private function __construct(
        private bool $ok,
        private mixed $value,
        private ?DomainError $error,
    ) {
    }

    public static function ok(mixed $value): self
    {
        return new self(true, $value, null);
    }

    public static function err(DomainError $error): self
    {
        return new self(false, null, $error);
    }

    public function isOk(): bool
    {
        return $this->ok;
    }

    public function isErr(): bool
    {
        return !$this->ok;
    }

    public function value(): mixed
    {
        if (!$this->ok) {
            throw new LogicException('Result::value() called on an error result.');
        }

        return $this->value;
    }

    public function error(): DomainError
    {
        if ($this->ok || $this->error === null) {
            throw new LogicException('Result::error() called on an ok result.');
        }

        return $this->error;
    }
}
