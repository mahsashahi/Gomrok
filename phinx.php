<?php

declare(strict_types=1);

use Gomrok\Config\Settings;

require __DIR__ . '/vendor/autoload.php';

$settings = Settings::fromEnvironment(__DIR__);
$db = $settings->database;

return [
    'paths' => [
        'migrations' => [
            'Gomrok\Database\Migrations' => __DIR__ . '/src/Database/Migrations',
        ],
        'seeds' => [
            'Gomrok\Database\Seeds' => __DIR__ . '/src/Database/Seeds',
        ],
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
            'collation' => 'utf8mb4_0900_ai_ci',
        ],
    ],
    'version_order' => 'creation',
];
