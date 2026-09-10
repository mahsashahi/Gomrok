<?php

declare(strict_types=1);

namespace Gomrok\Shared\Http;

use Gomrok\Shared\Application\ErrorLog\ErrorLogEntry;
use Gomrok\Shared\Application\ErrorLog\ErrorLogWriter;
use Gomrok\Shared\Infrastructure\CorrelationId;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Exception\HttpException;
use Throwable;

/**
 * Slim's default error handler, replaced so every unhandled error comes back as
 * JSON (never HTML). Slim `HttpException`s (404, 405, …) keep their status;
 * anything else is a 500 and is logged with a stack trace + correlation id.
 *
 * This is the *exception* half of the error model — the `Result` / `DomainError`
 * half is handled inside actions via {@see JsonResponder::problem()}.
 */
final readonly class JsonErrorHandler
{
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private JsonResponder $responder,
        private LoggerInterface $logger,
        private ErrorLogWriter $errorLog,
        private CorrelationId $correlationId,
    ) {
    }

    public function __invoke(
        ServerRequestInterface $request,
        Throwable $exception,
        bool $displayErrorDetails,
        bool $logErrors,
        bool $logErrorDetails,
    ): ResponseInterface {
        $isHttp = $exception instanceof HttpException;
        $status = $isHttp ? $exception->getCode() : 500;

        if (!$isHttp) {
            $correlationId = $this->correlationId->get();

            $this->logger->error('http.unhandled_exception', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
                'file' => $exception->getFile() . ':' . $exception->getLine(),
                'method' => $request->getMethod(),
                'path' => $request->getUri()->getPath(),
                'trace' => $logErrorDetails ? $exception->getTraceAsString() : null,
            ]);

            $this->errorLog->log(ErrorLogEntry::fromThrowable(
                $exception,
                'http',
                correlationId: $correlationId === '' ? null : $correlationId,
                context: [
                    'method' => $request->getMethod(),
                    'path' => $request->getUri()->getPath(),
                ],
            ));
        }

        $title = $isHttp
            ? $exception->getTitle()
            : ($displayErrorDetails ? $exception->getMessage() : 'Internal server error');

        $body = [
            'type' => 'about:blank',
            'title' => $title,
            'status' => $status,
        ];

        if (!$isHttp && $displayErrorDetails) {
            $body['exception'] = $exception::class;
            $body['at'] = $exception->getFile() . ':' . $exception->getLine();
        }

        return $this->responder->json($this->responseFactory->createResponse($status), $body, $status);
    }
}
