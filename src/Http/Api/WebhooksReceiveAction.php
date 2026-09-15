<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Webhooks\Application\IngestWebhookEvent\IngestWebhookEventCommand;
use Gomrok\Modules\Webhooks\Application\IngestWebhookEvent\IngestWebhookEventHandler;
use Gomrok\Modules\Webhooks\Application\IngestWebhookEvent\IngestWebhookEventResult;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/webhooks/{provider}/{token}` (Phase 25 Q5) — **public**,
 * outside the `/api/v1` authenticated group (no client ever sends a Gomrok
 * API key here; the opaque `{token}` plus the provider's own signature is
 * the authentication). `{provider}` is read only for logging/readability —
 * exactly matching the path `AddProviderAccountEndpointHandler` (Phase 9)
 * already hands operators when they register a webhook endpoint; only
 * `{token}` resolves the account.
 *
 * Always `200` once the event is stored and its signature verified,
 * regardless of the inline processing outcome (Phase 25 Q4) — a signature
 * failure or an unknown token are the only rejections (`401`/`404`); a
 * business-processing failure is never surfaced to the provider, since the
 * `webhook:retry-pending` cron job is the sole retry mechanism.
 */
final readonly class WebhooksReceiveAction
{
    public function __construct(
        private IngestWebhookEventHandler $handler,
        private JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $token = $args['token'] ?? '';

        /** @var array<string, string> $headers */
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[(string) $name] = implode(', ', $values);
        }

        $result = $this->handler->handle(new IngestWebhookEventCommand(
            token: $token,
            rawBody: (string) $request->getBody(),
            headers: $headers,
        ));

        if ($result->isErr()) {
            return $this->responder->problem($response, $result->error());
        }

        $value = $result->value();
        \assert($value instanceof IngestWebhookEventResult);

        return $this->responder->json($response, [
            'webhook_event_id' => $value->webhookEventId,
            'outcome' => $value->outcome,
        ]);
    }
}
