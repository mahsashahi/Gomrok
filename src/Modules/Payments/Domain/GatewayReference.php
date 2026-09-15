<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

use DateTimeImmutable;

/**
 * Makes reverse lookup possible from any provider webhook/callback (the
 * Gateway Reference Lookup Rule): given a provider account and a raw
 * reference string a webhook carries, find the client/checkout attempt/
 * payment/subscription it belongs to. Insert-only — a reference, once
 * recorded, is never rewritten.
 *
 * Exactly one of `checkoutAttemptId` / `paymentId` / `subscriptionId` is set
 * (Phase 24 Q1, extended Phase 26): a provider checkout-session reference is
 * created at `checkout_attempts.status = provider_checkout_created`, well
 * before the attempt reaches `confirmed` — `Payment::create()`'s
 * precondition — so no `payments` row exists yet at write time; a
 * subscription's real provider Subscription-resource reference isn't known
 * until `ReconcileCheckoutStatusHandler` creates the `Subscription` row
 * itself. The three named constructors below make that invariant explicit
 * rather than accepting one raw nullable id with an implicit contract. `id`
 * is null until persisted.
 */
final readonly class GatewayReference
{
    public function __construct(
        public ?int $id,
        public int $clientId,
        public int $providerAccountId,
        public GatewayReferenceType $referenceType,
        public string $referenceValue,
        public ?int $checkoutAttemptId,
        public ?int $paymentId,
        public ?int $subscriptionId,
        public DateTimeImmutable $createdAt,
    ) {
    }

    public static function forCheckoutAttempt(int $clientId, int $providerAccountId, GatewayReferenceType $referenceType, string $referenceValue, int $checkoutAttemptId, DateTimeImmutable $now): self
    {
        return new self(null, $clientId, $providerAccountId, $referenceType, trim($referenceValue), $checkoutAttemptId, null, null, $now);
    }

    public static function forPayment(int $clientId, int $providerAccountId, GatewayReferenceType $referenceType, string $referenceValue, int $paymentId, DateTimeImmutable $now): self
    {
        return new self(null, $clientId, $providerAccountId, $referenceType, trim($referenceValue), null, $paymentId, null, $now);
    }

    public static function forSubscription(int $clientId, int $providerAccountId, GatewayReferenceType $referenceType, string $referenceValue, int $subscriptionId, DateTimeImmutable $now): self
    {
        return new self(null, $clientId, $providerAccountId, $referenceType, trim($referenceValue), null, null, $subscriptionId, $now);
    }
}
