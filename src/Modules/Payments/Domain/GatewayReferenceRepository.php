<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

/**
 * Persistence port for {@see GatewayReference}. Insert-only — no update
 * method.
 */
interface GatewayReferenceRepository
{
    /**
     * @return int the new row's id
     */
    public function save(GatewayReference $reference): int;

    public function findByReference(int $providerAccountId, GatewayReferenceType $referenceType, string $referenceValue): ?GatewayReference;

    /**
     * @return list<GatewayReference>
     */
    public function forPayment(int $paymentId): array;
}
