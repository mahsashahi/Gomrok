<?php

declare(strict_types=1);

namespace Gomrok\Modules\Webhooks\Application\IngestWebhookEvent;

use Gomrok\Modules\Providers\Application\Adapter\ProviderAdapterFactory;
use Gomrok\Modules\Providers\Application\Adapter\ProviderWebhookVerificationFailed;
use Gomrok\Modules\Providers\Application\Adapter\RawWebhook;
use Gomrok\Modules\Providers\Application\Adapter\UnsupportedProviderType;
use Gomrok\Modules\Providers\Application\ProviderAccountCredentials;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Domain\EndpointKind;
use Gomrok\Modules\Webhooks\Application\ProcessWebhookEvent\ProcessWebhookEventHandler;
use Gomrok\Modules\Webhooks\Domain\WebhookEvent;
use Gomrok\Modules\Webhooks\Domain\WebhookEventRepository;
use Gomrok\Modules\Webhooks\Domain\WebhookEventStatus;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * `POST /api/v1/webhooks/{provider}/{token}` (Phase 25). Resolves the opaque
 * `token` to a provider account, verifies the signature, stores the event
 * (always — even a signature failure is stored, for audit), then hands it to
 * {@see ProcessWebhookEventHandler} for inline processing (Q3, user-specified).
 *
 * An unknown token or a failed signature is rejected before ever being
 * treated as a "processing failure" — those are security rejections
 * (401/404), not something the retry job should ever see. Once the event is
 * genuinely stored and verified, this always returns `ok()` regardless of the
 * inline processing outcome (Q4: the HTTP response is never used to signal a
 * processing failure to the provider — the `webhook:retry-pending` cron job
 * is the sole retry mechanism).
 */
final readonly class IngestWebhookEventHandler
{
    public function __construct(
        private ProviderAccountDirectory $accounts,
        private ProviderAccountCredentials $credentials,
        private ProviderAdapterFactory $adapterFactory,
        private WebhookEventRepository $events,
        private ProcessWebhookEventHandler $processor,
        private ClockInterface $clock,
    ) {
    }

    public function handle(IngestWebhookEventCommand $command): Result
    {
        $account = $this->accounts->findByEndpointToken($command->token);
        if ($account === null) {
            return Result::err(DomainError::notFound('webhook.unknown_token', 'No provider account is registered for this webhook token.'));
        }

        try {
            $adapter = $this->adapterFactory->for($account->id);
        } catch (UnsupportedProviderType $e) {
            return Result::err(DomainError::unsupported('webhook.provider_not_implemented', $e->getMessage()));
        }

        $secret = $this->credentials->endpointSigningSecret($account->id, EndpointKind::Webhook->value) ?? '';
        $headers = array_change_key_case($command->headers, CASE_UPPER);
        $signatureHeader = $headers['STRIPE-SIGNATURE'] ?? '';
        $rawWebhook = new RawWebhook($command->rawBody, $signatureHeader, $secret, $headers);

        $now = $this->clock->now();

        try {
            $parsed = $adapter->parseWebhook($rawWebhook);
        } catch (ProviderWebhookVerificationFailed $e) {
            $event = WebhookEvent::receive(
                $account->clientId,
                $account->id,
                $account->providerTypeCode,
                null,
                null,
                null,
                null,
                $command->rawBody,
                $headers,
                $now,
            );
            $event->markFailed($now, 'webhook.invalid_signature', $e->getMessage());
            $this->events->save($event);

            return Result::err(DomainError::unauthorized('webhook.invalid_signature', 'Webhook signature verification failed.'));
        }

        $existing = $this->events->findByDedupKey($account->id, $parsed->eventId, $parsed->rawStatus);
        if ($existing !== null && $existing->status() === WebhookEventStatus::Processed) {
            return Result::ok(new IngestWebhookEventResult($this->requireId($existing), 'duplicate'));
        }

        if ($existing !== null) {
            $event = $existing;
        } else {
            $event = WebhookEvent::receive(
                $account->clientId,
                $account->id,
                $account->providerTypeCode,
                $parsed->eventId,
                $parsed->eventType,
                $parsed->rawStatus,
                $parsed->providerReference,
                $command->rawBody,
                $headers,
                $now,
                $parsed->subscriptionReference,
            );
            $this->events->save($event);
        }

        $result = $this->processor->process($event);

        return Result::ok(new IngestWebhookEventResult($result->webhookEventId, $result->outcome));
    }

    private function requireId(WebhookEvent $event): int
    {
        $id = $event->id();
        \assert($id !== null);

        return $id;
    }
}
