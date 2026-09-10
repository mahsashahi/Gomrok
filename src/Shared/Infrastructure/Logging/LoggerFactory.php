<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Logging;

use Gomrok\Config\Settings;
use Gomrok\Shared\Infrastructure\CorrelationId;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Builds the application logger: one line of JSON per record on stdout, with the
 * correlation id and static context attached by {@see CorrelationIdProcessor}.
 */
final readonly class LoggerFactory
{
    public function __construct(
        private Settings $settings,
        private CorrelationId $correlationId,
    ) {
    }

    public function create(): LoggerInterface
    {
        $handler = new StreamHandler(
            'php://stdout',
            $this->settings->appDebug ? Level::Debug : Level::Info,
        );
        $handler->setFormatter(new JsonFormatter());

        $logger = new Logger('gomrok');
        $logger->pushHandler($handler);
        $logger->pushProcessor(new CorrelationIdProcessor(
            $this->correlationId,
            ['service' => 'gomrok', 'env' => $this->settings->appEnv],
        ));

        return $logger;
    }
}
