<?php

declare(strict_types=1);

namespace Gomrok\Tests\Integration;

use Gomrok\Config\Settings;
use Gomrok\Modules\Vouchers\Application\ConfirmVoucherRedemption\ConfirmVoucherRedemptionHandler;
use Gomrok\Modules\Vouchers\Application\ReleaseVoucherRedemption\ReleaseVoucherRedemptionHandler;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionCommand;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionHandler;
use Gomrok\Modules\Vouchers\Application\ReserveVoucherRedemption\ReserveVoucherRedemptionResult;
use Gomrok\Modules\Vouchers\Application\VoucherDiscountCalculator;
use Gomrok\Modules\Vouchers\Application\VoucherEligibilityEvaluator;
use Gomrok\Modules\Vouchers\Domain\DefaultDiscountType;
use Gomrok\Modules\Vouchers\Domain\Voucher;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherCurrencyDiscountRepository;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherEligibilityRuleRepository;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherRedemptionRepository;
use Gomrok\Modules\Vouchers\Infrastructure\PdoVoucherRepository;
use Gomrok\Shared\Infrastructure\Persistence\TransactionRunner;
use Gomrok\Shared\Infrastructure\SystemClock;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Phase 17 round-trip against real MySQL: reserve locks the `vouchers` row
 * (`FOR UPDATE`), a repeat reserve with the same attempt is idempotent, confirm
 * increments `redeemed_count` exactly once, and a released reservation frees
 * the cap for a new attempt. Skipped locally (no MySQL); runs in CI.
 */
final class VoucherRedemptionPersistenceTest extends TestCase
{
    private PDO $pdo;
    private int $clientId;

    protected function setUp(): void
    {
        $db = Settings::fromEnvironment(\dirname(__DIR__, 2))->database;

        try {
            $this->pdo = new PDO($db->dsn(), $db->user, $db->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $this->pdo->query('SELECT 1 FROM voucher_redemptions LIMIT 1');
        } catch (PDOException $e) {
            self::markTestSkipped('MySQL / schema not available (' . $e->getMessage() . ').');
        }

        $this->pdo->beginTransaction();
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->prepare(
            "INSERT INTO clients (slug, name, status, default_currency, timezone, notification_signing_secret, created_at)
             VALUES ('vr-test-client', 'VR Test', 'active', 'EUR', 'UTC', 's', :now)",
        )->execute(['now' => $now]);
        $this->clientId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    #[Test]
    public function reserveConfirmAndReleaseRoundTrip(): void
    {
        $vouchers = new PdoVoucherRepository($this->pdo);
        $redemptions = new PdoVoucherRedemptionRepository($this->pdo);
        $rules = new PdoVoucherEligibilityRuleRepository($this->pdo);
        $discounts = new PdoVoucherCurrencyDiscountRepository($this->pdo);
        $transactions = new TransactionRunner($this->pdo);
        $clock = new SystemClock();
        $audit = new RecordingAuditLogWriter();

        $voucher = Voucher::create($this->clientId, 'PERSIST1', 'Persist', null, null, null, false, null, null, DefaultDiscountType::Full, null, $clock->now());
        $voucher->setUsageLimits(1, null, null, $clock->now());
        $vouchers->save($voucher);
        $voucherId = $voucher->id();
        self::assertNotNull($voucherId);

        $reserve = new ReserveVoucherRedemptionHandler(
            $vouchers,
            $redemptions,
            new VoucherEligibilityEvaluator($rules, $discounts, $redemptions),
            new VoucherDiscountCalculator($discounts),
            $audit,
            $transactions,
            $clock,
        );
        $confirm = new ConfirmVoucherRedemptionHandler($vouchers, $redemptions, $audit, $transactions, $clock);
        $release = new ReleaseVoucherRedemptionHandler($vouchers, $redemptions, $audit, $transactions, $clock);

        $first = $reserve->handle(new ReserveVoucherRedemptionCommand($this->clientId, $voucherId, 'attempt-1', 'EUR', 2900));
        self::assertTrue($first->isOk(), $first->isErr() ? $first->error()->code : '');
        $firstPayload = $first->value();
        self::assertInstanceOf(ReserveVoucherRedemptionResult::class, $firstPayload);
        self::assertSame('reserved', $firstPayload->status);
        self::assertSame(0, $firstPayload->payableMinor);

        // global cap is 1: a second, different attempt is rejected while the first is reserved
        $second = $reserve->handle(new ReserveVoucherRedemptionCommand($this->clientId, $voucherId, 'attempt-2', 'EUR', 2900));
        self::assertTrue($second->isErr());
        self::assertSame('voucher.not_eligible', $second->error()->code);

        // idempotent replay of the same attempt
        $replay = $reserve->handle(new ReserveVoucherRedemptionCommand($this->clientId, $voucherId, 'attempt-1', 'EUR', 2900));
        $replayPayload = $replay->value();
        self::assertInstanceOf(ReserveVoucherRedemptionResult::class, $replayPayload);
        self::assertSame($firstPayload->redemptionId, $replayPayload->redemptionId);

        self::assertTrue($confirm->handle($voucherId, 'attempt-1', $this->clientId)->isOk());
        $reloaded = $vouchers->findById($voucherId);
        self::assertSame(1, $reloaded?->redeemedCount());

        // confirming again does not double-count
        self::assertTrue($confirm->handle($voucherId, 'attempt-1', $this->clientId)->isOk());
        self::assertSame(1, $vouchers->findById($voucherId)?->redeemedCount());

        // a confirmed redemption can never be released
        $released = $release->handle($voucherId, 'attempt-1', $this->clientId);
        self::assertTrue($released->isErr());
        self::assertSame('voucher_redemption.already_confirmed', $released->error()->code);
    }
}
