<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetDefaultPackagePrice;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Application\PricingAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePriceRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use InvalidArgumentException;

final readonly class SetDefaultPackagePriceHandler
{
    public function __construct(
        private DefaultPackagePriceRepository $prices,
        private PackageDirectory $packages,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
    ) {
    }

    public function handle(SetDefaultPackagePriceCommand $command): Result
    {
        if ($command->amountMinor < 0) {
            return Result::err(DomainError::validation('pricing.negative_amount', 'A price cannot be negative.'));
        }

        try {
            $currency = Currency::of($command->currency)->code();
        } catch (InvalidArgumentException) {
            return Result::err(DomainError::validation('pricing.invalid_currency', "'{$command->currency}' is not a valid ISO 4217 currency."));
        }
        if (!$this->reference->currencyExists($currency)) {
            return Result::err(DomainError::validation('pricing.unknown_currency', "Currency '{$currency}' is not configured.", ['currency' => $currency]));
        }

        $package = $this->packages->findById($command->packageId);
        if ($package === null) {
            return Result::err(DomainError::notFound('package.not_found', "Package {$command->packageId} was not found."));
        }

        $before = $this->prices->find($command->packageId);
        $price = new DefaultPackagePrice($command->packageId, $command->amountMinor, $currency);

        $clientId = $package->clientId;
        $this->transactions->run(function () use ($price, $before, $clientId, $command): void {
            $this->prices->save($price);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'default_package_price.set')
                : AuditEntry::forSystem('default_package_price.set', $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('package', $command->packageId)
                    ->withChange(
                        $before !== null ? PricingAuditSnapshot::defaultPrice($before) : null,
                        PricingAuditSnapshot::defaultPrice($price),
                    ),
            );
        });

        return Result::ok(null);
    }
}
