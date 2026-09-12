<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Application\ValidateVoucher;

use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\ResolvedPrice;
use Gomrok\Modules\Pricing\Domain\SubscriptionInterval;
use Gomrok\Modules\Providers\Domain\PaymentMethod;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Modules\Vouchers\Application\VoucherContext;
use Gomrok\Modules\Vouchers\Application\VoucherDiscountCalculator;
use Gomrok\Modules\Vouchers\Application\VoucherDiscountResult;
use Gomrok\Modules\Vouchers\Application\VoucherEligibilityEvaluator;
use Gomrok\Modules\Vouchers\Domain\VoucherRepository;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * `GET /api/v1/vouchers/validate` (Phase 19 Q3): resolves the package's price
 * exactly like `PricingResolveAction` does (Gomrok never trusts a
 * client-supplied amount), then runs the same {@see VoucherEligibilityEvaluator}
 * `ReserveVoucherRedemptionHandler` uses as its authoritative gate — here as an
 * unlocked, no-op pre-check — and, only when eligible, the Phase 17
 * {@see VoucherDiscountCalculator} for a preview. No reservation, no write.
 */
final readonly class ValidateVoucherHandler
{
    public function __construct(
        private PackageDirectory $packages,
        private PriceResolver $priceResolver,
        private VoucherRepository $vouchers,
        private VoucherEligibilityEvaluator $evaluator,
        private VoucherDiscountCalculator $calculator,
        private ClockInterface $clock,
    ) {
    }

    public function handle(ValidateVoucherCommand $command): Result
    {
        if (trim($command->packageCode) === '') {
            return Result::err(DomainError::validation('voucher_validate.package_required', 'A "package" is required.'));
        }
        if (trim($command->country) === '') {
            return Result::err(DomainError::validation('voucher_validate.country_required', 'A "country" is required.'));
        }
        if (trim($command->voucherCode) === '') {
            return Result::err(DomainError::validation('voucher_validate.code_required', 'A voucher "code" is required.'));
        }

        $method = null;
        if ($command->paymentMethod !== null) {
            $method = PaymentMethod::tryFrom($command->paymentMethod);
            if ($method === null) {
                return Result::err(DomainError::validation('voucher_validate.unknown_method', "Unknown payment method '{$command->paymentMethod}'.", ['method' => $command->paymentMethod]));
            }
        }

        $purchaseType = null;
        if ($command->purchaseType !== null) {
            $purchaseType = PurchaseType::tryFrom($command->purchaseType);
            if ($purchaseType === null) {
                return Result::err(DomainError::validation('voucher_validate.unknown_purchase_type', "Unknown purchase type '{$command->purchaseType}'.", ['purchase_type' => $command->purchaseType]));
            }
        }

        $interval = null;
        if ($command->subscriptionInterval !== null) {
            $interval = SubscriptionInterval::tryFrom($command->subscriptionInterval);
            if ($interval === null) {
                return Result::err(DomainError::validation('voucher_validate.unknown_interval', "Unknown subscription interval '{$command->subscriptionInterval}'.", ['interval' => $command->subscriptionInterval]));
            }
        }

        $package = $this->packages->find($command->clientId, $command->packageCode);
        if ($package === null) {
            return Result::err(DomainError::notFound('package.not_found', "Package '{$command->packageCode}' was not found."));
        }

        $priceResult = $this->priceResolver->resolve(
            $command->clientId,
            $package->id,
            $command->country,
            $command->deviceType,
            $method,
            $purchaseType,
            $interval,
        );
        if ($priceResult->isErr()) {
            return $priceResult;
        }
        $price = $priceResult->value();
        \assert($price instanceof ResolvedPrice);

        $voucher = $this->vouchers->findByCode($command->clientId, $command->voucherCode);
        if ($voucher === null) {
            return Result::err(DomainError::notFound('voucher.not_found', "Voucher '{$command->voucherCode}' was not found for this client."));
        }

        $context = new VoucherContext(
            clientId: $command->clientId,
            now: $this->clock->now(),
            country: $command->country,
            currency: $price->currencyCode,
            packageId: $package->id,
            paymentMethod: $method,
            purchaseType: $purchaseType,
            subscriptionInterval: $interval,
            amountMinor: $price->amountMinor,
            amountCurrency: $price->currencyCode,
            clientUserRef: $command->clientUserRef,
            isFirstPurchase: $command->isFirstPurchase,
        );

        $eligibility = $this->evaluator->evaluate($voucher, $context);

        if (!$eligibility->eligible) {
            return Result::ok(new ValidateVoucherResult(
                false,
                $eligibility->reasons,
                $voucher->code(),
                $voucher->name(),
                $price->amountMinor,
                $price->amountDecimal,
                $price->currencyCode,
                null,
                null,
                null,
            ));
        }

        $discountResult = $this->calculator->calculate($voucher, $price->currencyCode, $price->amountMinor);
        if ($discountResult->isErr()) {
            return $discountResult;
        }
        $discount = $discountResult->value();
        \assert($discount instanceof VoucherDiscountResult);

        return Result::ok(new ValidateVoucherResult(
            true,
            [],
            $voucher->code(),
            $voucher->name(),
            $price->amountMinor,
            $price->amountDecimal,
            $price->currencyCode,
            $discount->nominalDiscountMinor,
            $discount->appliedDiscountMinor,
            $discount->payableMinor,
        ));
    }
}
