<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Domain;

use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Domain\ProviderCapabilities;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProviderCapabilitiesTest extends TestCase
{
    #[Test]
    public function hasAndAll(): void
    {
        $caps = ProviderCapabilities::of(Capability::Refund, Capability::Webhook, Capability::Refund);

        self::assertTrue($caps->has(Capability::Refund));
        self::assertFalse($caps->has(Capability::PartialRefund));
        self::assertSame(2, $caps->count());
        // all() is in enum declaration order, de-duplicated
        self::assertSame([Capability::Refund, Capability::Webhook], $caps->all());
    }

    #[Test]
    public function noneIsEmpty(): void
    {
        self::assertTrue(ProviderCapabilities::none()->isEmpty());
        self::assertSame(0, ProviderCapabilities::none()->count());
    }

    #[Test]
    public function withoutRemovesCapabilities(): void
    {
        $caps = ProviderCapabilities::of(Capability::Refund, Capability::PartialRefund, Capability::Webhook)
            ->without(Capability::PartialRefund, Capability::CustomerPortal);

        self::assertSame([Capability::Refund, Capability::Webhook], $caps->all());
    }

    #[Test]
    public function intersect(): void
    {
        $a = ProviderCapabilities::of(Capability::Refund, Capability::PartialRefund, Capability::Webhook);
        $b = ProviderCapabilities::of(Capability::Webhook, Capability::Refund, Capability::ReturnUrl);

        self::assertSame([Capability::Refund, Capability::Webhook], $a->intersect($b)->all());
    }

    #[Test]
    public function equalsIgnoresInputOrder(): void
    {
        self::assertTrue(
            ProviderCapabilities::of(Capability::Webhook, Capability::Refund)
                ->equals(ProviderCapabilities::of(Capability::Refund, Capability::Webhook)),
        );
        self::assertFalse(
            ProviderCapabilities::of(Capability::Webhook)->equals(ProviderCapabilities::of(Capability::Refund)),
        );
    }
}
