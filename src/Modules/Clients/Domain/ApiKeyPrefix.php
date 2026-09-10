<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Domain;

/**
 * The mode a key operates in, encoded in the visible token prefix
 * (`gk_live_…` / `gk_test_…`). A test key is issued against the same client but
 * lets the client target sandbox provider configuration later.
 */
enum ApiKeyPrefix: string
{
    case Live = 'gk_live';
    case Test = 'gk_test';
}
