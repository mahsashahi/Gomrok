<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

/**
 * Optional (Phase 1 Q5) — a two-step authorize-then-capture flow, distinct
 * from {@see PaymentProviderPort::createPayment()}'s immediate-capture flow.
 */
interface SupportsAuthCapture
{
    /**
     * @throws ProviderAdapterException
     */
    public function authorizePayment(CreatePaymentCommand $command): ProviderPaymentResult;

    /**
     * @throws ProviderAdapterException
     */
    public function capturePayment(string $providerReference, ?int $amountMinor = null): ProviderPaymentResult;

    /**
     * @throws ProviderAdapterException
     */
    public function cancelPayment(string $providerReference): void;
}
