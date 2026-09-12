<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

use DateTimeImmutable;

/**
 * Makes reverse lookup possible from any provider webhook/callback (the
 * Gateway Reference Lookup Rule): given a provider account and a raw
 * reference string a webhook carries, find the client/payment/subscription it
 * belongs to. Insert-only — a reference, once recorded, is never rewritten.
 * `id` is null until persisted.
 */
final readonly class GatewayReference
{
    public function __construct(
        public ?int $id,
        public int $clientId,
        public int $providerAccountId,
        public GatewayReferenceType $referenceType,
        public string $referenceValue,
        public ?int $paymentId,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public static function record(int $clientId, int $providerAccountId, GatewayReferenceType $referenceType, string $referenceValue, ?int $paymentId, DateTimeImmutable $now): self
    {
        return new self(null, $clientId, $providerAccountId, $referenceType, trim($referenceValue), $paymentId, $now);
    }
}
