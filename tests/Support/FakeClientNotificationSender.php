<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Notifications\Application\ClientNotificationSender;
use Gomrok\Modules\Notifications\Application\ClientNotificationSendOutcome;

final class FakeClientNotificationSender implements ClientNotificationSender
{
    /** @var list<ClientNotificationSendOutcome> queued outcomes, consumed in order; last one repeats */
    private array $queue = [];

    /** @var list<array{url: string, body: string, signature: string}> */
    public array $calls = [];

    public function queue(ClientNotificationSendOutcome $outcome): void
    {
        $this->queue[] = $outcome;
    }

    public function send(string $url, string $body, string $signatureHeader): ClientNotificationSendOutcome
    {
        $this->calls[] = ['url' => $url, 'body' => $body, 'signature' => $signatureHeader];

        if ($this->queue === []) {
            return ClientNotificationSendOutcome::delivered(200, 'ok');
        }

        return \count($this->queue) > 1 ? array_shift($this->queue) : $this->queue[0];
    }
}
