<?php

declare(strict_types=1);

use Gomrok\Http\HealthAction;
use Slim\App;

return static function (App $app): void {
    $app->get('/health', HealthAction::class);
};
