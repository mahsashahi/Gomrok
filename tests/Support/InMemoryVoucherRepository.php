<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;

final class InMemoryVoucherRepository implements VoucherRepository
{
    /** @var array<int, Voucher> */
    private array $byId = [];

    private int $nextId = 1;

    public function save(Voucher $voucher): void
    {
        if ($voucher->id() === null) {
            $voucher->assignId($this->nextId++);
        }
        $id = $voucher->id();
        \assert($id !== null);
        $this->byId[$id] = $voucher;
    }

    public function findById(int $id): ?Voucher
    {
        return $this->byId[$id] ?? null;
    }

    public function findByIdForUpdate(int $id): ?Voucher
    {
        return $this->findById($id);
    }

    public function incrementRedeemedCount(int $id): void
    {
        $voucher = $this->byId[$id] ?? null;
        if ($voucher === null) {
            return;
        }
        $this->byId[$id] = Voucher::fromStorage(
            $id,
            $voucher->clientId(),
            $voucher->code(),
            $voucher->name(),
            $voucher->description(),
            $voucher->status(),
            $voucher->validFrom(),
            $voucher->validUntil(),
            $voucher->firstPurchaseOnly(),
            $voucher->minPurchaseMinor(),
            $voucher->minPurchaseCurrency(),
            $voucher->defaultDiscountType(),
            $voucher->defaultPercentBp(),
            $voucher->maxTotalRedemptions(),
            $voucher->maxPerUser(),
            $voucher->maxPerClient(),
            $voucher->redeemedCount() + 1,
            $voucher->createdAt(),
            $voucher->updatedAt(),
        );
    }

    public function findByCode(int $clientId, string $code): ?Voucher
    {
        $code = strtoupper(trim($code));
        foreach ($this->byId as $voucher) {
            if ($voucher->clientId() === $clientId && $voucher->code() === $code) {
                return $voucher;
            }
        }

        return null;
    }

    public function existsForClientWithCode(int $clientId, string $code): bool
    {
        return $this->findByCode($clientId, $code) !== null;
    }

    public function forClient(int $clientId): array
    {
        return array_values(array_filter($this->byId, static fn (Voucher $v): bool => $v->clientId() === $clientId));
    }
}
