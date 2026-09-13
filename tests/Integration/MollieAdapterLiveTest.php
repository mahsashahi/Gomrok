<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Modules\Providers\Application\Adapter\CreatePaymentCommand;
use Gomrok\Modules\Providers\Infrastructure\Adapter\Mollie\MollieAdapter;
use Gomrok\Tests\Support\InMemoryProviderTypeDeclarations;
use Mollie\Api\MollieApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 22's exit criterion, run for real: a genuine Mollie test-mode API
 * call, using a real API key. Skips (the same way every MySQL-dependent
 * integration test in this suite self-skips without a database) unless
 * `MOLLIE_TEST_API_KEY` is set in the environment — this session has no real
 * Mollie account, so this test self-skips here; it is expected to run for
 * real wherever that env var is configured. `tests/Unit/.../MollieAdapterTest.php`
 * covers the same request/response/error paths deterministically via the
 * SDK's own fake transport.
 */
final class MollieAdapterLiveTest extends TestCase
{
    private MollieAdapter $adapter;

    protected function setUp(): void
    {
        $apiKey = getenv('MOLLIE_TEST_API_KEY');
        if (!\is_string($apiKey) || $apiKey === '') {
            self::markTestSkipped('MOLLIE_TEST_API_KEY is not set — no real Mollie test account available.');
        }

        $this->adapter = new MollieAdapter((new MollieApiClient())->setApiKey($apiKey), InMemoryProviderTypeDeclarations::withKnownProviders());
    }

    #[Test]
    public function createsARealPaymentAndReadsItsStatusBack(): void
    {
        $result = $this->adapter->createPayment(new CreatePaymentCommand(
            attemptReference: 'gomrok-phase22-live-test-' . bin2hex(random_bytes(4)),
            amountMinor: 2900,
            currencyCode: 'EUR',
            description: 'Gomrok Phase 22 live integration test',
            successUrl: 'https://example.com/success',
            cancelUrl: 'https://example.com/cancel',
        ));

        self::assertStringStartsWith('tr_', $result->providerReference);
        self::assertStringStartsWith('https://www.mollie.com/', $result->redirectUrl);
        self::assertSame('open', $result->rawStatus);

        $status = $this->adapter->getPaymentStatus($result->providerReference);
        self::assertSame($result->providerReference, $status->providerReference);
    }
}
