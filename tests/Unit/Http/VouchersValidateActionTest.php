<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Http;

use DateTimeImmutable;
use Gomrok\Http\Api\VouchersValidateAction;
use Gomrok\Modules\Pricing\Application\PriceListResolver;
use Gomrok\Modules\Pricing\Application\PriceResolver;
use Gomrok\Modules\Pricing\Application\PriceRuleResolver;
use Gomrok\Modules\Pricing\Domain\DefaultPackagePrice;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Modules\Vouchers\Application\ValidateVoucher\ValidateVoucherHandler;
use Gomrok\Modules\Vouchers\Application\VoucherDiscountCalculator;
use Gomrok\Modules\Vouchers\Application\VoucherEligibilityEvaluator;
use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\JsonResponder;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientExchangeRateRepository;
use Gomrok\Tests\Support\InMemoryDefaultPackagePriceRepository;
use Gomrok\Tests\Support\InMemoryPriceListPackageRepository;
use Gomrok\Tests\Support\InMemoryPriceListRepository;
use Gomrok\Tests\Support\InMemoryPriceRuleRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupPackageRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupRepository;
use Gomrok\Tests\Support\InMemoryVoucherCurrencyDiscountRepository;
use Gomrok\Tests\Support\InMemoryVoucherEligibilityRuleRepository;
use Gomrok\Tests\Support\InMemoryVoucherRedemptionRepository;
use Gomrok\Tests\Support\InMemoryVoucherRepository;
use Gomrok\Tests\Support\StubPackageDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class VouchersValidateActionTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;

    #[Test]
    public function returnsEligibilityAndADiscountPreview(): void
    {
        $action = $this->action();

        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/vouchers/validate')
                ->withQueryParams(['package' => 'pro', 'country' => 'DE', 'code' => 'WELCOME10']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /**
         * @var array{
         *     eligible: bool,
         *     reasons: list<string>,
         *     voucher: array{code: string},
         *     price: array{amount_minor: int, currency: string},
         *     discount: array{applied_minor: int, payable_minor: int},
         * } $body
         */
        self::assertTrue($body['eligible']);
        self::assertSame([], $body['reasons']);
        self::assertSame('WELCOME10', $body['voucher']['code']);
        self::assertSame(2900, $body['price']['amount_minor']);
        self::assertSame('EUR', $body['price']['currency']);
        self::assertSame(290, $body['discount']['applied_minor']);
        self::assertSame(2610, $body['discount']['payable_minor']);
    }

    #[Test]
    public function missingVoucherCodeIsAValidationError(): void
    {
        $action = $this->action();

        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/vouchers/validate')
                ->withQueryParams(['package' => 'pro', 'country' => 'DE']),
            (new ResponseFactory())->createResponse(),
        );

        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{code: string} $body */
        self::assertSame('voucher_validate.code_required', $body['code']);
    }

    #[Test]
    public function unknownVoucherIs404WithNoDiscountKey(): void
    {
        $action = $this->action();

        $response = $action(
            (new ServerRequestFactory())->createServerRequest('GET', '/api/v1/vouchers/validate')
                ->withQueryParams(['package' => 'pro', 'country' => 'DE', 'code' => 'GHOST']),
            (new ResponseFactory())->createResponse(),
        );

        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        /** @var array{code: string} $body */
        self::assertSame('voucher.not_found', $body['code']);
        self::assertArrayNotHasKey('discount', $body);
    }

    private function action(): VouchersValidateAction
    {
        $now = new DateTimeImmutable('2026-09-11T12:00:00+00:00');
        $clock = new FrozenClock('2026-09-11T12:00:00+00:00');

        $directory = (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro', 'Pro');

        $groups = new InMemoryPricingGroupRepository();
        $groups->save(PricingGroup::define(self::CLIENT, PricingGroupSlug::of('default'), 'Default', 0, null, 'EUR', true, $now));
        $defaults = new InMemoryDefaultPackagePriceRepository();
        $defaults->save(new DefaultPackagePrice(self::PACKAGE, 2900, 'EUR'));

        $priceResolver = new PriceResolver(
            $groups,
            new InMemoryPricingGroupPackageRepository(),
            $defaults,
            new InMemoryClientExchangeRateRepository(),
            $directory,
            new PriceListResolver(new InMemoryPriceListRepository(), new InMemoryPriceListPackageRepository()),
            new PriceRuleResolver(new InMemoryPriceRuleRepository()),
            $clock,
        );

        $vouchers = new InMemoryVoucherRepository();
        $vouchers->save(Voucher::create(self::CLIENT, 'WELCOME10', 'Welcome', null, null, null, false, null, null, DefaultDiscountType::Percentage, 1000, $now));

        $evaluator = new VoucherEligibilityEvaluator(
            new InMemoryVoucherEligibilityRuleRepository(),
            new InMemoryVoucherCurrencyDiscountRepository(),
            new InMemoryVoucherRedemptionRepository(),
        );
        $calculator = new VoucherDiscountCalculator(new InMemoryVoucherCurrencyDiscountRepository());

        $handler = new ValidateVoucherHandler($directory, $priceResolver, $vouchers, $evaluator, $calculator, $clock);

        $context = new ClientContext();
        $context->set(new AuthenticatedClient(self::CLIENT, 'televika', 'Televika', 'active', 'EUR', 'DE', 'Europe/Berlin', 'gk_test'));

        return new VouchersValidateAction($context, $handler, new JsonResponder());
    }
}
