<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\SetPriceListPackagePrice;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Application\PriceListAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\PriceListPackage;
use Gomrok\Modules\Pricing\Domain\PriceListPackageRepository;
use Gomrok\Modules\Pricing\Domain\PriceListRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use InvalidArgumentException;

/**
 * Upserts an exact per-package price on a non-control A/B list. The amount must
 * be positive and its currency must equal the list's pricing-group currency.
 */
final readonly class SetPriceListPackagePriceHandler
{
    public function __construct(
        private PriceListRepository $lists,
        private PriceListPackageRepository $listPackages,
        private PricingGroupRepository $groups,
        private PackageDirectory $packages,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
    ) {
    }

    public function handle(SetPriceListPackagePriceCommand $command): Result
    {
        $list = $this->lists->findById($command->priceListId);
        if ($list === null || $list->clientId() !== $command->clientId) {
            return Result::err(DomainError::notFound('price_list.not_found', "Price list {$command->priceListId} was not found for this client."));
        }
        if ($list->isControl()) {
            return Result::err(DomainError::validation('price_list.control_has_no_package_prices', 'The control list uses the base price and cannot carry per-package amounts.'));
        }

        if ($command->amountMinor <= 0) {
            return Result::err(DomainError::validation('price_list_package.non_positive_amount', 'The amount must be greater than zero.'));
        }

        $package = $this->packages->findById($command->packageId);
        if ($package === null || $package->clientId !== $command->clientId) {
            return Result::err(DomainError::notFound('package.not_found', "Package {$command->packageId} was not found for this client."));
        }

        try {
            $currency = Currency::of($command->currencyCode)->code();
        } catch (InvalidArgumentException) {
            return Result::err(DomainError::validation('price_list_package.invalid_currency', "'{$command->currencyCode}' is not a valid ISO 4217 currency."));
        }
        if (!$this->reference->currencyExists($currency)) {
            return Result::err(DomainError::validation('price_list_package.unknown_currency', "Currency '{$currency}' is not configured.", ['currency' => $currency]));
        }

        $group = $this->groups->findById($list->pricingGroupId());
        \assert($group !== null);
        if ($currency !== $group->currencyCode()) {
            return Result::err(DomainError::validation(
                'price_list_package.currency_mismatch',
                "The amount currency ({$currency}) must match the pricing group's currency ({$group->currencyCode()}).",
                ['currency' => $currency, 'group_currency' => $group->currencyCode()],
            ));
        }

        $existing = $this->listPackages->find($command->priceListId, $command->packageId);
        $before = $existing !== null ? PriceListAuditSnapshot::listPackage($existing) : null;
        $created = $existing === null;
        $row = new PriceListPackage($command->priceListId, $command->packageId, $command->amountMinor, $currency);

        $this->transactions->run(function () use ($row, $before, $command, $list): void {
            $this->listPackages->save($row);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'price_list_package.set')
                : AuditEntry::forSystem('price_list_package.set', $command->clientId);

            $this->audit->record(
                $entry->withTarget('price_list', $command->priceListId)
                    ->withChange($before, PriceListAuditSnapshot::listPackage($row))
                    ->withContext(['pricing_group_id' => $list->pricingGroupId(), 'package_id' => $command->packageId]),
            );
        });

        return Result::ok(new SetPriceListPackagePriceResult($created));
    }
}
