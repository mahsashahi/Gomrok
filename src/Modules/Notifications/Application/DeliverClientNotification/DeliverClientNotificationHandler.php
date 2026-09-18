<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Application\DeliverClientNotification;

use Gomrok\Modules\Clients\Application\ClientNotificationSecret;
use Gomrok\Modules\Notifications\Application\ClientNotificationSender;
use Gomrok\Modules\Notifications\Application\NotificationSigner;
use Gomrok\Modules\Notifications\Domain\BackoffSchedule;
use Gomrok\Modules\Notifications\Domain\ClientNotification;
use Gomrok\Modules\Notifications\Domain\ClientNotificationRepository;
use Psr\Clock\ClockInterface;

/**
 * Attempts one delivery of a queued {@see ClientNotification} — used by both
 * the retry/delivery job (Phase 28 Q4) and, eventually, a manual admin retry
 * that wants an immediate attempt rather than waiting for the next cron tick.
 * Never throws; every outcome (success, retryable failure, exhausted) is
 * recorded on the row itself.
 */
final readonly class DeliverClientNotificationHandler
{
    public function __construct(
        private ClientNotificationRepository $notifications,
        private ClientNotificationSecret $secrets,
        private NotificationSigner $signer,
        private ClientNotificationSender $sender,
        private ClockInterface $clock,
    ) {
    }

    public function deliver(ClientNotification $notification): void
    {
        $now = $this->clock->now();
        $secret = $this->secrets->secretFor($notification->clientId());

        if ($secret === null) {
            $notification->recordDeadLetter($now, null, null, 'notification.no_signing_secret: client has no signing secret configured');
            $this->notifications->save($notification);

            return;
        }

        $signatureHeader = $this->signer->sign($secret, $notification->payload(), $now);
        $outcome = $this->sender->send($notification->endpointUrl(), $notification->payload(), $signatureHeader);

        if ($outcome->success) {
            $notification->recordSuccess($now, $outcome->responseStatus ?? 0, $outcome->responseBody);
            $this->notifications->save($notification);

            return;
        }

        $failedAttempts = $notification->attemptCount() + 1;
        if (BackoffSchedule::isExhausted($failedAttempts)) {
            $notification->recordDeadLetter($now, $outcome->responseStatus, $outcome->responseBody, $outcome->errorMessage);
        } else {
            $next = BackoffSchedule::nextAttemptAt($failedAttempts, $now);
            $notification->recordRetry($now, $outcome->responseStatus, $outcome->responseBody, $outcome->errorMessage, $next);
        }

        $this->notifications->save($notification);
    }
}
