<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\Jobs;

use Gomrok\Modules\Checkout\Application\ChangeCheckoutAttemptStatus\ChangeCheckoutAttemptStatusHandler;
use Gomrok\Modules\Checkout\Domain\CheckoutAttemptRepository;
use Gomrok\Shared\Application\Jobs\JobHandler;
use Gomrok\Shared\Application\Jobs\JobRunResult;
use Gomrok\Shared\Domain\Jobs\Job;
use Psr\Clock\ClockInterface;

/**
 * The checkout-attempt abandonment sweep (Phase 18's `abandoned_at` column,
 * modelled but undriven until Phase 29). A non-terminal attempt with no
 * activity for {@see STALE_AFTER_MINUTES} is marked `abandoned` — reusing
 * {@see ChangeCheckoutAttemptStatusHandler} unchanged, same guarded
 * `transitionTo()` and audit trail a real transition already gets.
 */
final readonly class CheckoutAbandonmentSweepHandler implements JobHandler
{
    private const STALE_AFTER_MINUTES = 60;
    private const BATCH_SIZE = 100;
    private const RECURRENCE_MINUTES = 15;

    public function __construct(
        private CheckoutAttemptRepository $attempts,
        private ChangeCheckoutAttemptStatusHandler $changeStatus,
        private ClockInterface $clock,
    ) {
    }

    public function type(): string
    {
        return 'checkout_abandonment_sweep';
    }

    public function recurrenceIntervalMinutes(): int
    {
        return self::RECURRENCE_MINUTES;
    }

    public function handle(Job $job): JobRunResult
    {
        $before = $this->clock->now()->modify('-' . self::STALE_AFTER_MINUTES . ' minutes');
        $stale = $this->attempts->findStaleNonTerminal($before, self::BATCH_SIZE);

        $abandoned = 0;
        foreach ($stale as $attempt) {
            $id = $attempt->id();
            if ($id === null) {
                continue;
            }
            $result = $this->changeStatus->handle($id, $attempt->clientId(), 'abandoned');
            if ($result->isOk()) {
                ++$abandoned;
            }
        }

        return JobRunResult::success(['scanned' => \count($stale), 'abandoned' => $abandoned]);
    }
}
