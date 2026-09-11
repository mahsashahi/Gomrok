<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Checkout\Application\CheckoutAttemptDirectory;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Modules\Checkout\Infrastructure\PdoCheckoutAttemptDirectory;
use Gomrok\Modules\Checkout\Infrastructure\PdoCheckoutAttemptRepository;

/**
 * PHP-DI definitions for the Checkout module (Phase 18 — the pre-payment
 * lifecycle anchor). Use-case handlers are autowired.
 *
 * @return array<string, mixed>
 */
return [
    CheckoutAttemptRepository::class => get(PdoCheckoutAttemptRepository::class),
    CheckoutAttemptDirectory::class => get(PdoCheckoutAttemptDirectory::class),
];
