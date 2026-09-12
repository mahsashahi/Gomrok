<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;

final class InMemoryGatewayReferenceRepository implements GatewayReferenceRepository
{
    /** @var array<int, GatewayReference> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(GatewayReference $reference): int
    {
        $id = $this->nextId++;
        $this->byId[$id] = new GatewayReference(
            $id,
            $reference->clientId,
            $reference->providerAccountId,
            $reference->referenceType,
            $reference->referenceValue,
            $reference->paymentId,
            $reference->createdAt,
        );

        return $id;
    }

    public function findByReference(int $providerAccountId, GatewayReferenceType $referenceType, string $referenceValue): ?GatewayReference
    {
        foreach ($this->byId as $reference) {
            if ($reference->providerAccountId === $providerAccountId && $reference->referenceType === $referenceType && $reference->referenceValue === $referenceValue) {
                return $reference;
            }
        }

        return null;
    }

    public function forPayment(int $paymentId): array
    {
        return array_values(array_filter($this->byId, static fn (GatewayReference $r): bool => $r->paymentId === $paymentId));
    }
}
