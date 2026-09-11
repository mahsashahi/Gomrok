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
