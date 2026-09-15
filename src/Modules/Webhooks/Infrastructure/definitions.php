<?php

declare(strict_types=1);

use function DI\get;

use Gomrok\Modules\Webhooks\Domain\WebhookEventRepository;
use Gomrok\Modules\Webhooks\Infrastructure\PdoWebhookEventRepository;

/**
 * PHP-DI definitions for the Webhooks module (Phase 25). Use-case handlers
 * are autowired.
 *
 * @return array<string, mixed>
 */
return [
    WebhookEventRepository::class => get(PdoWebhookEventRepository::class),
];
