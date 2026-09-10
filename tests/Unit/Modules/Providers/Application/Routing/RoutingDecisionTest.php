<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Providers\Application\Routing;

use Gomrok\Modules\Providers\Application\Routing\RejectedAccount;
use Gomrok\Modules\Providers\Application\Routing\RejectionReason;
use Gomrok\Modules\Providers\Application\Routing\RoutedAccount;
use Gomrok\Modules\Providers\Application\Routing\RoutingDecision;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RoutingDecisionTest extends TestCase
{
    #[Test]
    public function chosenIsTheFirstCandidate(): void
    {
        $decision = $this->decision();

        self::assertSame('mollie-de', $decision->chosen()->slug);
    }

    #[Test]
    public function roundTripsThroughToArrayAndFromArray(): void
    {
        $original = $this->decision();

        $restored = RoutingDecision::fromArray($original->toArray());

        self::assertEquals($original, $restored);
        self::assertSame(2, $restored->chosen()->accountId);
        self::assertSame(RejectionReason::ModeMismatch, $restored->rejections[0]->reason);
    }

    #[Test]
    public function toArrayCarriesAStableShape(): void
    {
        $array = $this->decision()->toArray();

        self::assertSame(RoutingDecision::VERSION, $array['version']);
        self::assertSame(2, $array['chosen_account_id']);
        self::assertSame('germany', $array['group']['slug']);
        self::assertCount(2, $array['candidates']);
        self::assertSame('mode_mismatch', $array['rejections'][0]['reason']);
    }

    private function decision(): RoutingDecision
    {
        return new RoutingDecision(
            clientId: 7,
            country: 'DE',
            currency: 'EUR',
            purchaseType: 'one_time_payment',
            paymentMethod: 'card',
            mode: 'test',
            deviceType: null,
            groupId: 3,
            groupSlug: 'germany',
            groupIsDefault: false,
            candidates: [
                new RoutedAccount(2, 'mollie-de', 'mollie', 'test', 0),
                new RoutedAccount(5, 'stripe-de', 'stripe', 'test', 1),
            ],
            rejections: [
                new RejectedAccount(9, 'stripe-live', RejectionReason::ModeMismatch),
            ],
        );
    }
}
