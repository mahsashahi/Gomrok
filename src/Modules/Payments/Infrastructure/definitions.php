<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Payments\Application\PaymentDirectory;
use Gomrok\Modules\Payments\Domain\GatewayReferenceRepository;
use Gomrok\Modules\Payments\Domain\PaymentAttemptRepository;
use Gomrok\Modules\Payments\Domain\PaymentRepository;
use Gomrok\Modules\Payments\Domain\ProviderCustomerRepository;
use Gomrok\Modules\Payments\Domain\ProviderTransactionRepository;
use Gomrok\Modules\Payments\Infrastructure\PdoGatewayReferenceRepository;
use Gomrok\Modules\Payments\Infrastructure\PdoPaymentAttemptRepository;
use Gomrok\Modules\Payments\Infrastructure\PdoPaymentDirectory;
use Gomrok\Modules\Payments\Infrastructure\PdoPaymentRepository;
use Gomrok\Modules\Payments\Infrastructure\PdoProviderCustomerRepository;
use Gomrok\Modules\Payments\Infrastructure\PdoProviderTransactionRepository;

/**
 * PHP-DI definitions for the Payments module (Phase 20 — aggregate &
 * lifecycle). Use-case handlers are autowired.
 *
 * @return array<string, mixed>
 */
return [
    PaymentRepository::class => get(PdoPaymentRepository::class),
    PaymentAttemptRepository::class => get(PdoPaymentAttemptRepository::class),
    ProviderTransactionRepository::class => get(PdoProviderTransactionRepository::class),
    ProviderCustomerRepository::class => get(PdoProviderCustomerRepository::class),
    GatewayReferenceRepository::class => get(PdoGatewayReferenceRepository::class),
    PaymentDirectory::class => get(PdoPaymentDirectory::class),
];
