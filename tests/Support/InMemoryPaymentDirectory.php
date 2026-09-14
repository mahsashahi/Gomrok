<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Payments\Application\PaymentDirectory;
use Gomrok\Modules\Payments\Application\PaymentSummary;
use Gomrok\Modules\Payments\Domain\Payment;

/**
 * A {@see PaymentDirectory} projected straight off an
 * {@see InMemoryPaymentRepository} — so a test that creates payments via the
 * repository (through the domain aggregate, the normal way) sees the same
 * data through the read-side directory, without duplicating storage.
 */
final readonly class InMemoryPaymentDirectory implements PaymentDirectory
{
    public function __construct(private InMemoryPaymentRepository $payments)
    {
    }

    public function findById(int $id): ?PaymentSummary
    {
        $payment = $this->payments->findById($id);

        return $payment !== null ? $this->toSummary($payment) : null;
    }

    public function findByCheckoutAttemptId(int $checkoutAttemptId): ?PaymentSummary
    {
        $payment = $this->payments->findByCheckoutAttemptId($checkoutAttemptId);

        return $payment !== null ? $this->toSummary($payment) : null;
    }

    public function forClient(int $clientId): array
    {
        return array_map($this->toSummary(...), $this->payments->forClient($clientId));
    }

    private function toSummary(Payment $payment): PaymentSummary
    {
        return new PaymentSummary(
            $payment->id() ?? 0,
            $payment->clientId(),
            $payment->checkoutAttemptId(),
            $payment->clientUserRef(),
            $payment->packageId(),
            $payment->country(),
            $payment->currencyCode(),
            $payment->amountMinor(),
            $payment->purchaseType()->value,
            $payment->paymentMethod()?->value,
            $payment->subscriptionInterval()?->value,
            $payment->status()->value,
            $payment->errorCode(),
            $payment->errorMessage(),
            $payment->createdAt()->format('Y-m-d H:i:s'),
            $payment->updatedAt()?->format('Y-m-d H:i:s'),
        );
    }
}
