<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\EnableClient;

final readonly class EnableClientCommand
{
    public function __construct(public int $clientId)
    {
    }
}
