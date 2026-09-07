<?php

declare(strict_types=1);

use Gomrok\Config\Settings;

require __DIR__ . '/vendor/autoload.php';

$settings = Settings::fromEnvironment(__DIR__);
$db = $settings->database;

return [
    'paths' => [
        'migrations' => __DIR__ . '/src/Database/Migrations',
        'seeds' => __DIR__ . '/src/Database/Seeds',
    ],
    'templates' => [
        'style' => 'up_down',
    ],
    'environments' => [
        'default_migration_table' => 'phinx_migrations',
        'default_environment' => 'default',
        'default' => [
            'adapter' => 'mysql',
            'host' => $db->host,
            'port' => $db->port,
            'name' => $db->name,
            'user' => $db->user,
            'pass' => $db->password,
            'charset' => $db->charset,
        ],
    ],
    'version_order' => 'creation',
];
