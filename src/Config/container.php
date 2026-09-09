<?php

declare(strict_types=1);

use function DI\autowire;
use function DI\factory;
use function DI\get;

use Gomrok\Config\DatabaseSettings;
use Gomrok\Config\Settings;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ErrorLog\ErrorLogWriter;
use Gomrok\Shared\Application\Idempotency\IdempotencyStore;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\TokenGenerator;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Http\IdempotencyMiddleware;
use Gomrok\Shared\Infrastructure\Logging\LoggerFactory;
use Gomrok\Shared\Infrastructure\Persistence\PdoAuditLogWriter;
use Gomrok\Shared\Infrastructure\Persistence\PdoErrorLogWriter;
use Gomrok\Shared\Infrastructure\Persistence\PdoIdempotencyStore;
use Gomrok\Shared\Infrastructure\Persistence\PdoReferenceCatalog;
use Gomrok\Shared\Infrastructure\Persistence\TransactionRunner;
use Gomrok\Shared\Infrastructure\RandomTokenGenerator;
use Gomrok\Shared\Infrastructure\SystemClock;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * PHP-DI definitions. Kept small on purpose — modules register their own
 * services from their phases. Nothing here depends on a module.
 *
 * Closures are autowired by parameter type, so no manual `$container->get()`.
 *
 * @return array<string, mixed>
 */
return [
    Settings::class => factory(
        static fn (): Settings => Settings::fromEnvironment(dirname(__DIR__, 2)),
    ),

    DatabaseSettings::class => factory(
        static fn (Settings $settings): DatabaseSettings => $settings->database,
    ),

    PDO::class => factory(static function (DatabaseSettings $db): PDO {
        return new PDO(
            $db->dsn(),
            $db->user,
            $db->password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );
    }),

    ClockInterface::class => get(SystemClock::class),

    // Cross-cutting persistence ports (Phase 5).
    IdempotencyStore::class => get(PdoIdempotencyStore::class),
    AuditLogWriter::class => get(PdoAuditLogWriter::class),
    ErrorLogWriter::class => get(PdoErrorLogWriter::class),

    // Shared application ports (Phase 6).
    TokenGenerator::class => get(RandomTokenGenerator::class),
    ReferenceCatalog::class => get(PdoReferenceCatalog::class),
    Transactions::class => get(TransactionRunner::class),

    LoggerInterface::class => factory(
        static fn (LoggerFactory $loggerFactory): LoggerInterface => $loggerFactory->create(),
    ),

    ResponseFactoryInterface::class => get(ResponseFactory::class),

    // The /api/v1 group is the only user of this middleware — configure it to
    // require an Idempotency-Key on writes (Phase 7 Q5) and to have no replay
    // resolver yet (replay returns a pointer body).
    IdempotencyMiddleware::class => autowire()
        ->constructorParameter('replayResolver', null)
        ->constructorParameter('requireKeyOnWrites', true),
];
