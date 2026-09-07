<?php

declare(strict_types=1);

use Gomrok\Bootstrap\AppFactory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

AppFactory::create()->run();
