<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Adapter;

use Gomrok\Modules\Payments\Domain\PaymentStatus;
use Gomrok\Modules\Providers\Domain\ProviderCapabilities;

/**
 * Required of every provider adapter (Phase 1 Q5 — the hybrid shape;
 * `Architecture.md` §8). `createPayment()` is the one hosted-flow entry point
 * (Phase 21 Q1) — for Stripe it creates a Checkout Session; for Ziraat, a
 * bank-hosted redirect page. Anything not required of every provider lives in
 * one of the optional capability interfaces below, implemented only where the
 * provider really supports it — a provider that doesn't implement
 * {@see SupportsSubscriptions} makes "subscribe via this provider" impossible
 * at the type level, not a runtime throw.
 *
 * Every method may throw a {@see ProviderAdapterException} for a transport or
 * provider-level fault (Phase 21 Q2) — never for an unsupported capability,
 * which is prevented before the call by which interfaces the adapter
 * implements, and by the caller checking {@see getCapabilities()} first.
 */
interface PaymentProviderPort
{
    /**
     * @throws ProviderAdapterException
     */
    public function createPayment(CreatePaymentCommand $command): ProviderPaymentResult;

    /**
     * @throws ProviderAdapterException
     */
    public function getPaymentStatus(string $providerReference): ProviderPaymentStatus;

    public function verifyWebhookSignature(RawWebhook $webhook): bool;

    /**
     * @throws ProviderWebhookVerificationFailed when the signature does not verify
     */
    public function parseWebhook(RawWebhook $webhook): ParsedWebhookEvent;

    public function mapProviderStatusToInternalStatus(string $providerStatus): PaymentStatus;

    public function getCapabilities(): ProviderCapabilities;
}
