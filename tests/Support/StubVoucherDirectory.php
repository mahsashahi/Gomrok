<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Vouchers\Application\VoucherDirectory;
use Gomrok\Modules\Vouchers\Application\VoucherSummary;

/**
 * In-memory {@see VoucherDirectory} holding ready-made {@see VoucherSummary}
 * rows — the summary already carries its eligibility rules and currency
 * discounts, so tests build the exact projection they want rather than having
 * the double re-derive one.
 */
final class StubVoucherDirectory implements VoucherDirectory
{
    /** @var list<VoucherSummary> */
    private array $summaries = [];

    public function add(VoucherSummary $summary): self
    {
        $this->summaries[] = $summary;

        return $this;
    }

    public function forClient(int $clientId): array
    {
        return array_values(array_filter(
            $this->summaries,
            static fn (VoucherSummary $v): bool => $v->clientId === $clientId,
        ));
    }

    public function findByCode(int $clientId, string $code): ?VoucherSummary
    {
        foreach ($this->summaries as $summary) {
            if ($summary->clientId === $clientId && $summary->code === strtoupper($code)) {
                return $summary;
            }
        }

        return null;
    }

    public function findById(int $id): ?VoucherSummary
    {
        foreach ($this->summaries as $summary) {
            if ($summary->id === $id) {
                return $summary;
            }
        }

        return null;
    }
}
