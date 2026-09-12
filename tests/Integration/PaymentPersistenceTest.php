<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Config\Settings;
use Gomrok\Modules\Payments\Domain\GatewayReference;
use Gomrok\Modules\Payments\Domain\GatewayReferenceType;
use Gomrok\Modules\Payments\Domain\Payment;
use Gomrok\Modules\Payments\Domain\PaymentAttempt;
use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Payments\Domain\ProviderCustomer;
use Gomrok\Modules\Payments\Domain\ProviderTransaction;
use Gomrok\Modules\Payments\Infrastructure\PdoGatewayReferenceRepository;
use Gomrok\Modules\Payments\Infrastructure\PdoPaymentAttemptRepository;
use Gomrok\Modules\Payments\Infrastructure\PdoPaymentRepository;
use Gomrok\Modules\Payments\Infrastructure\PdoProviderCustomerRepository;
use Gomrok\Modules\Payments\Infrastructure\PdoProviderTransactionRepository;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 20 round-trip against real MySQL: a `payments` row survives a status
 * transition, and its `payment_attempts` / `provider_transactions` /
 * `provider_customers` / `gateway_references` children all round-trip
 * faithfully. Skipped locally (no MySQL); runs in CI.
 */
final class PaymentPersistenceTest extends TestCase
{
    private PDO $pdo;
    private int $clientId;
    private int $packageId;
    private int $checkoutAttemptId;
    private int $providerAccountId;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->query('SELECT 1 FROM payments LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL / schema not available (' . $e->getMessage() . ').');
        }

        $this->pdo->beginTransaction();
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->prepare(
            "INSERT INTO clients (slug, name, status, default_currency, timezone, notification_signing_secret, created_at)
             VALUES ('pay-test-client', 'Pay Test', 'active', 'EUR', 'UTC', 's', :now)",
        )->execute(['now' => $now]);
        $this->clientId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO packages (client_id, code, name, status, created_at) VALUES (:c, 'pro', 'Pro', 'active', :now)",
        )->execute(['c' => $this->clientId, 'now' => $now]);
        $this->packageId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO checkout_attempts (client_id, attempt_reference, package_id, country, currency_code, status, created_at)
             VALUES (:c, 'order-1', :p, 'DE', 'EUR', 'confirmed', :now)",
        )->execute(['c' => $this->clientId, 'p' => $this->packageId, 'now' => $now]);
        $this->checkoutAttemptId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO provider_types (code, name, requires_registration, api_capable) VALUES ('stripe_pay_test', 'Stripe', 0, 1)",
        )->execute();
        $providerTypeId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO provider_accounts (client_id, provider_type_id, slug, name, mode, status, public_key, secret_ciphertext, secret_last_four, created_at)
             VALUES (:c, :t, 'stripe-test', 'Stripe Test', 'test', 'active', 'pk_test', 'x', '1234', :now)",
        )->execute(['c' => $this->clientId, 't' => $providerTypeId, 'now' => $now]);
        $this->providerAccountId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function aPaymentAndItsChildRowsRoundTrip(): void
    {
        $payments = new PdoPaymentRepository($this->pdo);
        $attempts = new PdoPaymentAttemptRepository($this->pdo);
        $transactions = new PdoProviderTransactionRepository($this->pdo);
        $customers = new PdoProviderCustomerRepository($this->pdo);
        $references = new PdoGatewayReferenceRepository($this->pdo);
        $now = new \DateTimeImmutable('2026-09-11T12:00:00+00:00');

        $payment = Payment::create($this->clientId, $this->checkoutAttemptId, 'user-1', $this->packageId, 'DE', 'EUR', 2900, PurchaseType::OneTimePayment, null, null, $now);
        $payments->save($payment);
        $paymentId = $payment->id();
        self::assertNotNull($paymentId);

        $error = $payment->transitionTo(PaymentStatus::Pending, $now);
        self::assertNull($error);
        $payments->save($payment);

        $reloaded = $payments->findByCheckoutAttemptId($this->checkoutAttemptId);
        self::assertNotNull($reloaded);
        self::assertSame(PaymentStatus::Pending, $reloaded->status());
        self::assertSame(2900, $reloaded->amountMinor());

        $attempt = PaymentAttempt::start($paymentId, $this->providerAccountId, 1, null, $now);
        $attempts->save($attempt);
        $attemptId = $attempt->id();
        self::assertNotNull($attemptId);

        $transactionId = $transactions->save(ProviderTransaction::record($attemptId, 'authorize', ['amount' => 2900], ['id' => 'pi_1'], 'requires_action', $now));
        self::assertGreaterThan(0, $transactionId);

        $loadedTransactions = $transactions->forAttempt($attemptId);
        self::assertCount(1, $loadedTransactions);
        self::assertSame('pi_1', $loadedTransactions[0]->responsePayload['id'] ?? null);

        $customer = ProviderCustomer::link($this->clientId, $this->providerAccountId, 'user-1', 'cus_1', $now);
        $customers->save($customer);
        self::assertNotNull($customers->findByProviderCustomerId($this->providerAccountId, 'cus_1'));

        $referenceId = $references->save(GatewayReference::record($this->clientId, $this->providerAccountId, GatewayReferenceType::PaymentIntent, 'pi_1', $paymentId, $now));
        self::assertGreaterThan(0, $referenceId);

        $loadedReference = $references->findByReference($this->providerAccountId, GatewayReferenceType::PaymentIntent, 'pi_1');
        self::assertNotNull($loadedReference);
        self::assertSame($paymentId, $loadedReference->paymentId);
    }
}
