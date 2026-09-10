<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Pricing\Application;

use DateTimeImmutable;
use Gomrok\Modules\Pricing\Application\DeletePriceRule\DeletePriceRuleHandler;
use Gomrok\Modules\Pricing\Application\SetPriceRule\SetPriceRuleCommand;
use Gomrok\Modules\Pricing\Application\SetPriceRule\SetPriceRuleHandler;
use Gomrok\Modules\Pricing\Application\SetPriceRule\SetPriceRuleResult;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryPriceRuleRepository;
use Gomrok\Tests\Support\InMemoryPricingGroupRepository;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubPackageDirectory;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PriceRuleHandlersTest extends TestCase
{
    private const CLIENT = 7;
    private const PACKAGE = 42;

    private InMemoryPriceRuleRepository $rules;
    private InMemoryPricingGroupRepository $groups;
    private RecordingAuditLogWriter $audit;
    private FrozenClock $clock;
    private int $dachId;

    protected function setUp(): void
    {
        $this->rules = new InMemoryPriceRuleRepository();
        $this->groups = new InMemoryPricingGroupRepository();
        $this->audit = new RecordingAuditLogWriter();
        $this->clock = new FrozenClock('2026-09-10T12:00:00+00:00');

        $dach = PricingGroup::define(self::CLIENT, PricingGroupSlug::of('dach'), 'DACH', 1, null, 'EUR', false, new DateTimeImmutable('now'));
        $this->groups->save($dach);
        $id = $dach->id();
        self::assertNotNull($id);
        $this->dachId = $id;
    }

    #[Test]
    public function setValidatesEnumsOwnershipAndConsistency(): void
    {
        $handler = $this->handler();

        self::assertSame('price_rule.unknown_method', $handler->handle(new SetPriceRuleCommand(self::CLIENT, self::PACKAGE, paymentMethod: 'crypto', currencyCode: 'EUR', amountMinor: 100))->error()->code);
        self::assertSame('price_rule.interval_needs_subscription', $handler->handle(new SetPriceRuleCommand(self::CLIENT, self::PACKAGE, purchaseType: 'one_time_payment', subscriptionInterval: 'yearly', currencyCode: 'EUR', amountMinor: 100))->error()->code);
        self::assertSame('price_rule.needs_group_or_currency', $handler->handle(new SetPriceRuleCommand(self::CLIENT, self::PACKAGE, paymentMethod: 'card', amountMinor: 100))->error()->code);
        self::assertSame('price_rule.group_not_owned', $handler->handle(new SetPriceRuleCommand(self::CLIENT, self::PACKAGE, pricingGroupId: 999, amountMinor: 100))->error()->code);
        self::assertSame('price_rule.currency_mismatch', $handler->handle(new SetPriceRuleCommand(self::CLIENT, self::PACKAGE, pricingGroupId: $this->dachId, currencyCode: 'USD', amountMinor: 100))->error()->code);
        self::assertSame('price_rule.unavailable_has_amount', $handler->handle(new SetPriceRuleCommand(self::CLIENT, self::PACKAGE, currencyCode: 'EUR', isAvailable: false, amountMinor: 100))->error()->code);
    }

    #[Test]
    public function setUpsertsByTheDimensionTuple(): void
    {
        $handler = $this->handler();

        $first = $handler->handle(new SetPriceRuleCommand(self::CLIENT, self::PACKAGE, paymentMethod: 'card', currencyCode: 'EUR', amountMinor: 2500));
        self::assertTrue($first->isOk());
        $firstPayload = $first->value();
        self::assertInstanceOf(SetPriceRuleResult::class, $firstPayload);
        self::assertTrue($firstPayload->created);

        $second = $handler->handle(new SetPriceRuleCommand(self::CLIENT, self::PACKAGE, paymentMethod: 'card', currencyCode: 'EUR', amountMinor: 2400));
        $secondPayload = $second->value();
        self::assertInstanceOf(SetPriceRuleResult::class, $secondPayload);
        self::assertFalse($secondPayload->created);
        self::assertSame($firstPayload->ruleId, $secondPayload->ruleId);

        self::assertCount(1, $this->rules->forClientPackage(self::CLIENT, self::PACKAGE));
        self::assertSame(2400, $this->rules->findById($firstPayload->ruleId)?->amountMinor());
        self::assertContains('price_rule.set', $this->audit->actions());
    }

    #[Test]
    public function deleteScopesToTheClient(): void
    {
        $created = $this->handler()->handle(new SetPriceRuleCommand(self::CLIENT, self::PACKAGE, currencyCode: 'EUR', amountMinor: 100));
        $payload = $created->value();
        self::assertInstanceOf(SetPriceRuleResult::class, $payload);

        $delete = new DeletePriceRuleHandler($this->rules, $this->audit, new SynchronousTransactions());

        self::assertSame('price_rule.not_found', $delete->handle($payload->ruleId, 999)->error()->code);
        self::assertTrue($delete->handle($payload->ruleId, self::CLIENT)->isOk());
        self::assertNull($this->rules->findById($payload->ruleId));
        self::assertContains('price_rule.deleted', $this->audit->actions());
    }

    private function handler(): SetPriceRuleHandler
    {
        return new SetPriceRuleHandler(
            $this->rules,
            (new StubPackageDirectory())->add(self::PACKAGE, self::CLIENT, 'pro'),
            $this->groups,
            new StubProviderAccountDirectory(),
            new InMemoryReferenceCatalog(),
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );
    }
}
