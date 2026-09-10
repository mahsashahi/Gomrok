<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * Whether a provider account holds live or sandbox credentials (Phase 9 Q2).
 * Maps 1:1 to the client API key's `gk_live` / `gk_test` prefix; the Phase 10
 * router forbids a test request from resolving a live account.
 */
enum ProviderAccountMode: string
{
    case Live = 'live';
    case Test = 'test';
}
