<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Notifications\Application\ClientNotificationDirectory;
use Gomrok\Modules\Notifications\Application\ClientNotificationSender;
use Gomrok\Modules\Notifications\Application\NotificationSigner;
use Gomrok\Modules\Notifications\Domain\ClientNotificationRepository;
use Gomrok\Modules\Notifications\Domain\ProviderAccountNotificationOverrideRepository;
use Gomrok\Modules\Notifications\Infrastructure\GuzzleClientNotificationSender;
use Gomrok\Modules\Notifications\Infrastructure\HmacNotificationSigner;
use Gomrok\Modules\Notifications\Infrastructure\PdoClientNotificationDirectory;
use Gomrok\Modules\Notifications\Infrastructure\PdoClientNotificationRepository;
use Gomrok\Modules\Notifications\Infrastructure\PdoProviderAccountNotificationOverrideRepository;

/**
 * PHP-DI definitions for the Notifications module (Phase 28). Use-case
 * handlers and the two `DomainEventSubscriber`s are autowired; the
 * `DomainEventDispatcher`'s subscriber list is assembled in
 * `src/Config/container.php` since it's cross-module by nature.
 *
 * @return array<string, mixed>
 */
return [
    ClientNotificationRepository::class => get(PdoClientNotificationRepository::class),
    ClientNotificationDirectory::class => get(PdoClientNotificationDirectory::class),
    ProviderAccountNotificationOverrideRepository::class => get(PdoProviderAccountNotificationOverrideRepository::class),
    NotificationSigner::class => get(HmacNotificationSigner::class),
    ClientNotificationSender::class => get(GuzzleClientNotificationSender::class),
];
