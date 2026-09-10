<?php

declare(strict_types=1);

namespace Gomrok\Bootstrap;

use DI\ContainerBuilder;
use Psr\Container\ContainerInterface;

/**
 * Builds the PHP-DI container from `src/Config/container.php` plus each module's
 * `Infrastructure/definitions.php`. Shared by the HTTP entrypoint
 * ({@see AppFactory}) and CLI runners so they wire dependencies identically.
 */
final class ContainerFactory
{
    /**
     * Module definition files, in load order. Add a row per module.
     *
     * @var list<string>
     */
    private const MODULE_DEFINITIONS = [
        'src/Modules/Clients/Infrastructure/definitions.php',
        'src/Modules/Providers/Infrastructure/definitions.php',
        'src/Modules/Packages/Infrastructure/definitions.php',
        'src/Modules/Pricing/Infrastructure/definitions.php',
    ];

    public static function create(): ContainerInterface
    {
        $rootDir = \dirname(__DIR__, 2);

        $builder = new ContainerBuilder();

        /** @var array<string, mixed> $definitions */
        $definitions = require $rootDir . '/src/Config/container.php';
        $builder->addDefinitions($definitions);

        foreach (self::MODULE_DEFINITIONS as $relativePath) {
            /** @var array<string, mixed> $moduleDefinitions */
            $moduleDefinitions = require $rootDir . '/' . $relativePath;
            $builder->addDefinitions($moduleDefinitions);
        }

        return $builder->build();
    }
}
