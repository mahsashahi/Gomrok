<?php

declare(strict_types=1);

namespace Gomrok\Modules\Notifications\Infrastructure;

use Gomrok\Modules\Notifications\Application\ClientNotificationSender;
use Gomrok\Modules\Notifications\Application\ClientNotificationSendOutcome;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * Short timeouts deliberately — this calls an arbitrary client server, not a
 * trusted payment provider, and must never hang the delivery/retry job.
 */
final class GuzzleClientNotificationSender implements ClientNotificationSender
{
    private GuzzleClient $client;

    public function __construct(?GuzzleClient $client = null)
    {
        $this->client = $client ?? new GuzzleClient([
            'connect_timeout' => 3.0,
            'timeout' => 8.0,
            'http_errors' => false,
        ]);
    }

    public function send(string $url, string $body, string $signatureHeader): ClientNotificationSendOutcome
    {
        try {
            $response = $this->client->post($url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Gomrok-Signature' => $signatureHeader,
                ],
                'body' => $body,
            ]);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            if ($response === null) {
                return ClientNotificationSendOutcome::unreachable($e->getMessage());
            }
        } catch (GuzzleException $e) {
            return ClientNotificationSendOutcome::unreachable($e->getMessage());
        }

        $status = $response->getStatusCode();
        $responseBody = (string) $response->getBody();

        return $status >= 200 && $status < 300
            ? ClientNotificationSendOutcome::delivered($status, $responseBody)
            : ClientNotificationSendOutcome::rejected($status, $responseBody);
    }
}
