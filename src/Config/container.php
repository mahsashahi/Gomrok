<?php

declare(strict_types=1);

use function DI\factory;

use Gomrok\Config\DatabaseSettings;
use Gomrok\Config\Settings;

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
];
