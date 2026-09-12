<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Modules\Providers\Application\Adapter\CreatePaymentCommand;
use Gomrok\Modules\Providers\Infrastructure\Adapter\Stripe\StripeAdapter;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;

/**
 * Phase 21's exit criterion, run for real: a genuine Stripe test-mode API
 * call, using a real secret key. Skips (the same way every MySQL-dependent
 * integration test in this suite self-skips without a database) unless
 * `STRIPE_TEST_SECRET_KEY` is set in the environment — this session has no
 * real Stripe account, so this test self-skips here; it is expected to run
 * for real wherever that env var is configured (e.g. GitHub Actions with a
 * repository secret). `tests/Unit/.../StripeAdapterTest.php` covers the same
 * request/response/error paths deterministically via a fake transport.
 */
final class StripeAdapterLiveTest extends TestCase
{
    private StripeAdapter $adapter;

    protected function setUp(): void
    {
        $secret = getenv('STRIPE_TEST_SECRET_KEY');
        if (!\is_string($secret) || $secret === '') {
            self::markTestSkipped('STRIPE_TEST_SECRET_KEY is not set — no real Stripe test account available.');
        }

        $this->adapter = new StripeAdapter(new StripeClient($secret), InMemoryProviderTypeDeclarations::withKnownProviders());
    }

    #[Test]
    public function createsARealCheckoutSessionAndReadsItsStatusBack(): void
    {
        $result = $this->adapter->createPayment(new CreatePaymentCommand(
            attemptReference: 'gomrok-phase21-live-test-' . bin2hex(random_bytes(4)),
            amountMinor: 2900,
            currencyCode: 'eur',
            description: 'Gomrok Phase 21 live integration test',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        ));

        self::assertStringStartsWith('cs_test_', $result->providerReference);
        self::assertStringStartsWith('https://checkout.stripe.com/', $result->redirectUrl);
        self::assertSame('open', $result->rawStatus);

        $status = $this->adapter->getPaymentStatus($result->providerReference);
        self::assertSame($result->providerReference, $status->providerReference);
    }
}
