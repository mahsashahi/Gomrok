<?php

declare(strict_types=1);

namespace Gomrok\Shared\Domain;

/**
 * An expected, caller-actionable failure — carried inside a {@see Result}, never
 * thrown. `code` is a stable machine string (`voucher.expired`); `message` is
 * human-readable and safe to return to the client.
 */
final readonly class DomainError
{
    /**
     * @param array<string, scalar|null> $context
     */
    private function __construct(
        public ErrorType $type,
        public string $code,
        public string $message,
        public array $context = [],
    ) {
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function validation(string $code, string $message, array $context = []): self
    {
        return new self(ErrorType::Validation, $code, $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function notFound(string $code, string $message, array $context = []): self
    {
        return new self(ErrorType::NotFound, $code, $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function conflict(string $code, string $message, array $context = []): self
    {
        return new self(ErrorType::Conflict, $code, $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function forbidden(string $code, string $message, array $context = []): self
    {
        return new self(ErrorType::Forbidden, $code, $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function unauthorized(string $code, string $message, array $context = []): self
    {
        return new self(ErrorType::Unauthorized, $code, $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function unsupported(string $code, string $message, array $context = []): self
    {
        return new self(ErrorType::Unsupported, $code, $message, $context);
    }

    /**
     * @param array<string, scalar|null> $context
     */
    public static function ruleViolation(string $code, string $message, array $context = []): self
    {
        return new self(ErrorType::RuleViolation, $code, $message, $context);
    }

    public function httpStatus(): int
    {
        return $this->type->httpStatus();
    }
}
