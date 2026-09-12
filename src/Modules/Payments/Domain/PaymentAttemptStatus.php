<?php

declare(strict_types=1);

namespace Gomrok\Modules\Payments\Domain;

/**
 * Deliberately smaller and separate from {@see PaymentStatus} (Phase 20 Q3) —
 * an attempt only ever answers "did this particular try against a provider
 * work or not," while the parent {@see Payment} carries the rich lifecycle.
 */
enum PaymentAttemptStatus: string
{
    case Started = 'started';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
}
