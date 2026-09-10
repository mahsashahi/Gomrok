<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Domain;

use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Domain\CapabilityGroup;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CapabilityTest extends TestCase
{
    #[Test]
    public function everyCapabilityBelongsToAKnownGroupWithALabel(): void
    {
        $groups = array_map(static fn (CapabilityGroup $g): string => $g->value, CapabilityGroup::cases());

        foreach (Capability::cases() as $capability) {
            self::assertContains($capability->group()->value, $groups);
            self::assertMatchesRegularExpression('/\S/', $capability->label());
        }
    }

    #[Test]
    public function purchaseTypesAreNotCapabilities(): void
    {
        $capabilityValues = array_map(static fn (Capability $c): string => $c->value, Capability::cases());

        foreach (PurchaseType::cases() as $purchaseType) {
            self::assertNotContains($purchaseType->value, $capabilityValues, "'{$purchaseType->value}' must not be in the Capability enum");
        }
    }

    #[Test]
    public function labelIsHumanReadable(): void
    {
        self::assertSame('Partial Refund', Capability::PartialRefund->label());
        self::assertSame('Three D Secure', Capability::ThreeDSecure->label());
    }
}
