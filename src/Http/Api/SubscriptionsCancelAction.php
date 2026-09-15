<?php

declare(strict_types=1);

namespace Gomrok\Http\Api;

use Gomrok\Modules\Checkout\Application\CheckoutAttemptDirectory;
use Gomrok\Modules\Subscriptions\Application\CancelSubscription\CancelSubscriptionCommand;
use Gomrok\Modules\Subscriptions\Application\CancelSubscription\CancelSubscriptionHandler;
use Gomrok\Modules\Subscriptions\Application\CancelSubscription\CancelSubscriptionResult;
use Gomrok\Modules\Subscriptions\Application\SubscriptionDirectory;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Http\ClientContext;
use Gomrok\Shared\Http\IdempotencyContext;
use Gomrok\Shared\Http\JsonResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * `POST /api/v1/subscriptions/{id}/cancel` (Phase 26) — `{id}` is the
 * checkout attempt id, matching every other `/api/v1/subscriptions/{id}*`
 * route (mirrors `PaymentsCancelAction`). Resolves it to the real
 * subscription first — cancelling only makes sense once one exists.
 */
final readonly class SubscriptionsCancelAction
{
    public function __construct(
        private ClientContext $context,
        private CheckoutAttemptDirectory $attempts,
        private SubscriptionDirectory $subscriptions,
        private CancelSubscriptionHandler $handler,
        private IdempotencyContext $idempotency,
        private JsonResponder $responder,
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $clientId = $this->context->clientId();
        $id = ctype_digit($args['id'] ?? '') ? (int) $args['id'] : 0;

        $attempt = $id > 0 ? $this->attempts->findById($id) : null;
        if ($attempt === null || $attempt->clientId !== $clientId) {
            return $this->responder->problem($response, DomainError::notFound('subscription.not_found', "Subscription {$id} was not found."));
        }

        $subscription = $this->subscriptions->findByCheckoutAttemptId($id);
        if ($subscription === null) {
            return $this->responder->problem($response, DomainError::notFound('subscription.not_found', "Subscription {$id} was not found."));
        }

        $result = $this->handler->handle(new CancelSubscriptionCommand($clientId, $subscription->id));
        if ($result->isErr()) {
            return $this->responder->problem($response, $result->error());
        }

        $value = $result->value();
        \assert($value instanceof CancelSubscriptionResult);

        $this->idempotency->setTarget('subscription', $value->subscriptionId);

        return $this->responder->json($response, [
            'checkout_attempt_id' => $id,
            'subscription_id' => $value->subscriptionId,
            'status' => $value->status,
        ]);
    }
}
