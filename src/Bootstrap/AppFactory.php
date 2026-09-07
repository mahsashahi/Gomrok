<?php

declare(strict_types=1);

namespace Gomrok\Bootstrap;

use Closure;
use DI\ContainerBuilder;
use Gomrok\Config\Settings;
use Psr\Container\ContainerInterface;
use Slim\App;
use Slim\Factory\AppFactory as SlimAppFactory;

/**
 * Builds the fully wired Slim application: DI container, routes, middleware.
 */
final class AppFactory
{
    /**
     * @return App<ContainerInterface|null>
     */
    public static function create(): App
    {
        $rootDir = \dirname(__DIR__, 2);

        /** @var array<string, mixed> $definitions */
        $definitions = require $rootDir . '/src/Config/container.php';

        $container = (new ContainerBuilder())
            ->addDefinitions($definitions)
            ->build();

        SlimAppFactory::setContainer($container);
        $app = SlimAppFactory::create();

        $registerRoutes = require $rootDir . '/src/Config/routes.php';
        if (!$registerRoutes instanceof Closure) {
            throw new \LogicException('src/Config/routes.php must return a Closure.');
        }
        $registerRoutes($app);

        $settings = Settings::fromEnvironment($rootDir);

        $app->addRoutingMiddleware();
        $app->addBodyParsingMiddleware();
        $app->addErrorMiddleware($settings->appDebug, true, true);

        return $app;
    }
}
