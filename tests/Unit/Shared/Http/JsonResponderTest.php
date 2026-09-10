<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Http;

use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Http\JsonResponder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ResponseFactory;

final class JsonResponderTest extends TestCase
{
    private JsonResponder $responder;

    protected function setUp(): void
    {
        $this->responder = new JsonResponder();
    }

    #[Test]
    public function jsonWritesBodyStatusAndContentType(): void
    {
        $response = $this->responder->json(
            (new ResponseFactory())->createResponse(),
            ['status' => 'ok'],
            201,
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('{"status":"ok"}', (string) $response->getBody());
    }

    #[Test]
    public function problemMapsDomainErrorToStatusAndBody(): void
    {
        $error = DomainError::notFound('payment.not_found', 'No such payment', ['id' => 7]);

        $response = $this->responder->problem((new ResponseFactory())->createResponse(), $error);

        self::assertSame(404, $response->getStatusCode());

        /** @var array{title: string, status: int, code: string, errorType: string, context: array{id: int}} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('No such payment', $body['title']);
        self::assertSame(404, $body['status']);
        self::assertSame('payment.not_found', $body['code']);
        self::assertSame('not_found', $body['errorType']);
        self::assertSame(7, $body['context']['id']);
    }
}
