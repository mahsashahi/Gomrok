<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Reconciliation\Application\ReconciliationFindingDirectory;
use Gomrok\Modules\Reconciliation\Domain\ReconciliationFindingRepository;
use Gomrok\Modules\Reconciliation\Infrastructure\PdoReconciliationFindingDirectory;
use Gomrok\Modules\Reconciliation\Infrastructure\PdoReconciliationFindingRepository;

/**
 * PHP-DI definitions for the Reconciliation module (Phase 29). Use-case
 * handlers and job handlers are autowired.
 *
 * @return array<string, mixed>
 */
return [
    ReconciliationFindingRepository::class => get(PdoReconciliationFindingRepository::class),
    ReconciliationFindingDirectory::class => get(PdoReconciliationFindingDirectory::class),
];
