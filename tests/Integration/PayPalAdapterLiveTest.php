<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Modules\Providers\Application\Adapter\CreatePaymentCommand;
use Gomrok\Modules\Providers\Infrastructure\Adapter\PayPal\PayPalAdapter;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 22's exit criterion, run for real: a genuine PayPal sandbox API call
 * using real REST credentials. Skips (the same way every MySQL-dependent
 * integration test in this suite self-skips without a database) unless both
 * `PAYPAL_TEST_CLIENT_ID` and `PAYPAL_TEST_CLIENT_SECRET` are set — this
 * session has no real PayPal sandbox app, so this test self-skips here.
 * `tests/Unit/.../PayPalAdapterTest.php` covers the same request/response/
 * error paths deterministically via Guzzle's `MockHandler`.
 */
final class PayPalAdapterLiveTest extends TestCase
{
    private PayPalAdapter $adapter;

    protected function setUp(): void
    {
        $clientId = getenv('PAYPAL_TEST_CLIENT_ID');
        $clientSecret = getenv('PAYPAL_TEST_CLIENT_SECRET');
        if (!\is_string($clientId) || $clientId === '' || !\is_string($clientSecret) || $clientSecret === '') {
            self::markTestSkipped('PAYPAL_TEST_CLIENT_ID / PAYPAL_TEST_CLIENT_SECRET are not set — no real PayPal sandbox app available.');
        }

        $this->adapter = new PayPalAdapter(
            new Client(),
            $clientId,
            $clientSecret,
            'https://api-m.sandbox.paypal.com',
            InMemoryProviderTypeDeclarations::withKnownProviders(),
        );
    }

    #[Test]
    public function createsARealOrderAndReadsItsStatusBack(): void
    {
        $result = $this->adapter->createPayment(new CreatePaymentCommand(
            attemptReference: 'gomrok-phase22-live-test-' . bin2hex(random_bytes(4)),
            amountMinor: 2900,
            currencyCode: 'EUR',
            description: 'Gomrok Phase 22 live integration test',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        ));

        self::assertNotSame('', $result->providerReference);
        self::assertStringContainsString('paypal.com', $result->redirectUrl);
        self::assertSame('CREATED', $result->rawStatus);

        $status = $this->adapter->getPaymentStatus($result->providerReference);
        self::assertSame($result->providerReference, $status->providerReference);
    }
}
