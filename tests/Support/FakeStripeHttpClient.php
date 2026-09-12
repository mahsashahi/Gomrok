<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Stripe\HttpClient\ClientInterface;

/**
 * A queued-response fake transport for `\Stripe\ApiRequestor` (swapped in via
 * `\Stripe\ApiRequestor::setHttpClient()`), so `StripeAdapter` can be tested
 * against the **real** Stripe PHP SDK's request-building and response-parsing
 * logic — including its own exception mapping (a 401 genuinely produces a
 * real `AuthenticationException`) — with no real network call and no real
 * credentials. Every request made is recorded for assertions.
 */
final class FakeStripeHttpClient implements ClientInterface
{
    /** @var list<array{status: int, body: string}> */
    private array $queue = [];

    /** @var list<array{method: string, url: string, params: array<array-key, mixed>}> */
    public array $requests = [];

    /**
     * @param array<array-key, mixed> $body
     */
    public function queue(int $status, array $body): self
    {
        $json = json_encode($body);
        $this->queue[] = ['status' => $status, 'body' => $json === false ? '{}' : $json];

        return $this;
    }

    /**
     * @param 'delete'|'get'|'post'      $method
     * @param array<array-key, mixed>    $headers
     * @param array<array-key, mixed>    $params
     * @param 'v1'|'v2'                  $apiMode
     *
     * @return array{0: string, 1: int, 2: array<array-key, mixed>}
     */
    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
    {
        $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

        $next = array_shift($this->queue);
        if ($next === null) {
            throw new \RuntimeException("FakeStripeHttpClient: no queued response for {$method} {$absUrl}");
        }

        return [$next['body'], $next['status'], []];
    }
}
