<?php

declare(strict_types=1);

namespace Gomrok\Shared\Infrastructure\Events;

use Gomrok\Shared\Application\ErrorLog\ErrorLogEntry;
use Gomrok\Shared\Application\ErrorLog\ErrorLogWriter;
use Gomrok\Shared\Application\Events\DomainEventDispatcher;
use Gomrok\Shared\Application\Events\DomainEventSubscriber;
use Gomrok\Shared\Domain\DomainEvent;
use Throwable;

/**
 * Calls every subscribed handler in-process, synchronously (Architecture.md
 * §5). A subscriber that throws is logged and skipped — never allowed to
 * propagate, since the triggering transaction has already committed by the
 * time {@see dispatch()} runs; a reaction failing must not look like the
 * original action failed.
 */
final readonly class SynchronousDomainEventDispatcher implements DomainEventDispatcher
{
    /**
     * @param list<DomainEventSubscriber> $subscribers
     */
    public function __construct(
        private array $subscribers,
        private ErrorLogWriter $errorLog,
    ) {
    }

    public function dispatch(DomainEvent $event): void
    {
        foreach ($this->subscribers as $subscriber) {
            if (!\in_array($event::class, $subscriber->subscribesTo(), true)) {
                continue;
            }

            try {
                $subscriber->handle($event);
            } catch (Throwable $e) {
                $this->errorLog->log(ErrorLogEntry::fromThrowable(
                    $e,
                    'domain_event',
                    context: ['event' => $event::class, 'subscriber' => $subscriber::class],
                ));
            }
        }
    }
}
