<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Http;

use Gomrok\Shared\Application\ErrorLog\ErrorLogEntry;
use Gomrok\Shared\Application\ErrorLog\ErrorLogWriter;
use Gomrok\Shared\Http\JsonErrorHandler;
use Gomrok\Shared\Http\JsonResponder;
use Gomrok\Shared\Infrastructure\CorrelationId;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Slim\Exception\HttpNotFoundException;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class JsonErrorHandlerTest extends TestCase
{
    private JsonErrorHandler $handler;

    /** @var ErrorLogWriter&object{entries: list<ErrorLogEntry>} */
    private ErrorLogWriter $errorLog;

    protected function setUp(): void
    {
        $this->errorLog = new class () implements ErrorLogWriter {
            /** @var list<ErrorLogEntry> */
            public array $entries = [];

            public function log(ErrorLogEntry $entry): void
            {
                $this->entries[] = $entry;
            }
        };

        $correlationId = new CorrelationId();
        $correlationId->set('corr-xyz');

        $this->handler = new JsonErrorHandler(
            new ResponseFactory(),
            new JsonResponder(),
            new NullLogger(),
            $this->errorLog,
            $correlationId,
        );
    }

    #[Test]
    public function unhandledExceptionBecomesJson500(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/x');

        $response = ($this->handler)($request, new RuntimeException('boom'), false, true, false);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));

        /** @var array{title: string, status: int} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Internal server error', $body['title']);
        self::assertSame(500, $body['status']);
        self::assertArrayNotHasKey('exception', $body);

        self::assertCount(1, $this->errorLog->entries);
        $entry = $this->errorLog->entries[0];
        self::assertSame('http', $entry->source);
        self::assertSame(RuntimeException::class, $entry->exceptionClass);
        self::assertSame('corr-xyz', $entry->correlationId);
    }

    #[Test]
    public function debugModeExposesTheException(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/x');

        $response = ($this->handler)($request, new RuntimeException('boom'), true, true, true);

        /** @var array{title: string, exception: string} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('boom', $body['title']);
        self::assertSame(RuntimeException::class, $body['exception']);
    }

    #[Test]
    public function slimHttpExceptionKeepsItsStatus(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/missing');

        $response = ($this->handler)($request, new HttpNotFoundException($request), false, true, false);

        self::assertSame(404, $response->getStatusCode());

        /** @var array{status: int} $body */
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(404, $body['status']);

        // Slim HttpExceptions are expected control flow — not error-logged.
        self::assertCount(0, $this->errorLog->entries);
    }
}
