<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Domain;

/**
 * The one baseline price for a package (Phase 13 Q2). `amountMinor` is in
 * `currencyCode`'s minor unit.
 */
final readonly class DefaultPackagePrice
{
    public string $currencyCode;

    public function __construct(
        public int $packageId,
        public int $amountMinor,
        string $currencyCode,
    ) {
        $this->currencyCode = strtoupper($currencyCode);
    }
}
