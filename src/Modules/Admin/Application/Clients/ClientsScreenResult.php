<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Clients;

final readonly class ClientsScreenResult
{
    /**
     * @param list<ClientRow> $rows
     */
    public function __construct(
        public ClientStats $stats,
        public string $activeFilter,
        public array $rows,
        public ?ClientDetail $selected,
    ) {
    }
}
