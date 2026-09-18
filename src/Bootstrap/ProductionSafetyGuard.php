<?php

declare(strict_types=1);

namespace Gomrok\Bootstrap;

use Gomrok\Config\Settings;

/**
 * "Verify local/dev-only helpers cannot leak into production" (Phase 30A
 * Q5, user-specified: an automated boot-time guard, not a manual checklist
 * item). Runs once per process, from {@see ContainerFactory::create()} —
 * the one bootstrap path every entrypoint (the web app via
 * {@see AppFactory}, `bin/Worker.php`, and every other `bin/*.php` script)
 * already goes through, so a single check point covers all of them.
 *
 * A no-op for `local`/`testing` (`.env` and `phpunit.xml` both set these) —
 * every check below is about what's unsafe *outside* dev/test.
 */
final class ProductionSafetyGuard
{
    /** @var list<string> */
    private const SAFE_ENVIRONMENTS = ['local', 'testing'];

    /**
     * @throws ProductionSafetyViolation
     */
    public static function check(Settings $settings): void
    {
        if (\in_array($settings->appEnv, self::SAFE_ENVIRONMENTS, true)) {
            return;
        }

        $violations = [];

        if ($settings->appDebug) {
            $violations[] = 'APP_DEBUG is enabled — this leaks stack traces and internal error '
                . 'detail in HTTP error responses (see JsonErrorHandler). Set APP_DEBUG=false.';
        }

        if ($settings->checkoutReturnTokenSecret === 'gomrokimo') {
            $violations[] = 'The checkout return-token secret is still the hardcoded development '
                . 'default ("gomrokimo") — this value is public (it is in this codebase\'s source '
                . 'history) and must never sign a real token. A real secret is not yet wired to an '
                . 'environment variable (Phase 24 Q4, deliberately deferred) — this must be fixed '
                . 'before running outside local/testing.';
        }

        if ($settings->encryptionKeyBase64 === null) {
            $violations[] = 'APP_ENCRYPTION_KEY is not set — provider secret encryption/decryption '
                . '(SodiumSecretCipher) will fail the first time a provider account is touched. '
                . 'Set a real base64-encoded 32-byte key.';
        }

        if ($violations !== []) {
            throw new ProductionSafetyViolation($violations);
        }
    }
}
