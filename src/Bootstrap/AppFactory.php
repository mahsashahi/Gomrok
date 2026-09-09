<?php

declare(strict_types=1);

namespace Gomrok\Bootstrap;

use Closure;
use Gomrok\Config\Settings;
use Gomrok\Shared\Http\CorrelationIdMiddleware;
use Gomrok\Shared\Http\JsonErrorHandler;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

/**
 * Builds the fully wired Slim application: DI container, routes, middleware.
 */
final class AppFactory
{
    /**
     * @param ContainerInterface|null $container overrides the default container
     *                                           (tests inject one with stubbed
     *                                           adapters so no DB is needed)
     *
     * @return App<ContainerInterface|null>
     */
    public static function create(?ContainerInterface $container = null): App
    {
        $rootDir = \dirname(__DIR__, 2);

        $container ??= ContainerFactory::create();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();

        $registerRoutes = require $rootDir . '/src/Config/routes.php';
        if (!$registerRoutes instanceof Closure) {
            throw new \LogicException('src/Config/routes.php must return a Closure.');
        }
        $registerRoutes($app);

        $settings = Settings::fromEnvironment($rootDir);

        $app->add(CorrelationIdMiddleware::class);
        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();

        $errorMiddleware = $app->addErrorMiddleware($settings->appDebug, true, true);
        $errorMiddleware->setDefaultErrorHandler(JsonErrorHandler::class);

        return $app;
    }
}
