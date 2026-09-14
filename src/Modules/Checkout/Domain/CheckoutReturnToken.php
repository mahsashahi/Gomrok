<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Domain;

/**
 * A signed, single-purpose token proving a return URL was genuinely generated
 * by Gomrok for one checkout attempt (Phase 24 Q4) — **not** proof of
 * payment; the return endpoint must still verify the actual status with the
 * provider's own API. Format: exactly two `_`-separated parts,
 * `{checkoutAttemptId}_{hash}` (`hash = HMAC-SHA256(checkoutAttemptId,
 * secret)`) — a single query parameter, no separate id/hash pair.
 *
 * Exists because Mollie/PayPal (unlike Stripe, which can substitute a
 * `{CHECKOUT_SESSION_ID}` placeholder into the URL it redirects back to)
 * offer no way to echo their own reference into a success/cancel URL —
 * Gomrok must decide that URL *before* the provider hands back any
 * reference, so the correlation key has to be something Gomrok can compute
 * on its own ahead of time.
 */
final readonly class CheckoutReturnToken
{
    private function __construct(
        public int $checkoutAttemptId,
        public string $hash,
    ) {
    }

    public static function issue(int $checkoutAttemptId, string $secret): self
    {
        return new self($checkoutAttemptId, self::computeHash($checkoutAttemptId, $secret));
    }

    /**
     * `null` when the token is not exactly two non-empty `_`-separated parts
     * with a valid positive integer id — malformed, not merely unverified.
     */
    public static function parse(string $token): ?self
    {
        $parts = explode('_', $token);
        if (\count($parts) !== 2) {
            return null;
        }

        [$idPart, $hash] = $parts;
        if ($idPart === '' || !ctype_digit($idPart) || $hash === '') {
            return null;
        }

        return new self((int) $idPart, $hash);
    }

    /**
     * Timing-safe: a forged or stale-secret hash must not be distinguishable
     * from a genuine one by response time.
     */
    public function verify(string $secret): bool
    {
        return hash_equals(self::computeHash($this->checkoutAttemptId, $secret), $this->hash);
    }

    public function __toString(): string
    {
        return "{$this->checkoutAttemptId}_{$this->hash}";
    }

    private static function computeHash(int $checkoutAttemptId, string $secret): string
    {
        return hash_hmac('sha256', (string) $checkoutAttemptId, $secret);
    }
}
