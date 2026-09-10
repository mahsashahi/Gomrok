<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure;

/**
 * Replaces the values of secret-bearing keys with a placeholder before data is
 * written somewhere durable (audit logs, error-log context). Matching is on the
 * key name, recursively, and case-insensitive.
 *
 * This is defence in depth — provider secrets and full API keys should not be in
 * these arrays in the first place (`CLAUDE.md` security rules).
 */
final class SecretRedactor
{
    public const PLACEHOLDER = '[redacted]';

    private const SENSITIVE_KEY_PATTERN =
        '/(secret|password|passwd|pwd|token|api[_-]?key|private[_-]?key|client[_-]?secret|authorization|signature|webhook[_-]?secret)/i';

    /**
     * @param array<array-key, mixed> $data
     *
     * @return array<array-key, mixed>
     */
    public static function redact(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (\is_string($key) && preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
                $out[$key] = self::PLACEHOLDER;

                continue;
            }

            $out[$key] = \is_array($value) ? self::redact($value) : $value;
        }

        return $out;
    }
}
