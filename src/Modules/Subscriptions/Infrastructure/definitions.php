<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Subscriptions\Application\SubscriptionDirectory;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionEventRepository;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionPaymentLinkRepository;
use Gomrok\Modules\Subscriptions\Domain\SubscriptionRepository;
use Gomrok\Modules\Subscriptions\Infrastructure\PdoSubscriptionDirectory;
use Gomrok\Modules\Subscriptions\Infrastructure\PdoSubscriptionEventRepository;
use Gomrok\Modules\Subscriptions\Infrastructure\PdoSubscriptionPaymentLinkRepository;
use Gomrok\Modules\Subscriptions\Infrastructure\PdoSubscriptionRepository;

/**
 * PHP-DI definitions for the Subscriptions module (Phase 26). Use-case
 * handlers are autowired.
 *
 * @return array<string, mixed>
 */
return [
    SubscriptionRepository::class => get(PdoSubscriptionRepository::class),
    SubscriptionEventRepository::class => get(PdoSubscriptionEventRepository::class),
    SubscriptionPaymentLinkRepository::class => get(PdoSubscriptionPaymentLinkRepository::class),
    SubscriptionDirectory::class => get(PdoSubscriptionDirectory::class),
];
