<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Clients\Application;

use Gomrok\Modules\Clients\Application\Authenticate\ApiKeyAuthenticator;
use Gomrok\Modules\Clients\Application\Authenticate\AuthAttempt;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;
use Gomrok\Modules\Clients\Domain\ApiKeyStatus;
use Gomrok\Modules\Clients\Domain\AuthFailureReason;
use Gomrok\Modules\Clients\Domain\ClientApiKey;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Shared\Http\AuthRequestMeta;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryClientApiKeyRepository;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\RecordingAuthAttemptLog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ApiKeyAuthenticatorTest extends TestCase
{
    private const KEY_ID = '0123456789abcdef';
    private const SECRET = 'supersecretsecret123';
    private const TOKEN = 'gk_live_0123456789abcdef.supersecretsecret123';

    private InMemoryClientApiKeyRepository $keys;
    private InMemoryClientDirectory $clients;
    private RecordingAuthAttemptLog $attempts;
    private FrozenClock $clock;
    private ApiKeyAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->keys = new InMemoryClientApiKeyRepository();
        $this->clients = new InMemoryClientDirectory();
        $this->attempts = new RecordingAuthAttemptLog();
        $this->clock = new FrozenClock('2026-09-08T12:00:00+00:00');

        $this->authenticator = new ApiKeyAuthenticator(
            $this->keys,
            $this->clients,
            $this->attempts,
            $this->clock,
            new NullLogger(),
        );
    }

    #[Test]
    public function authenticatesAValidKey(): void
    {
        $this->seedActiveClientWithKey();

        $result = $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta());

        self::assertTrue($result->ok);
        $client = $result->client();
        self::assertSame(7, $client->id);
        self::assertSame('televika', $client->slug);
        self::assertSame('live', $client->keyMode);

        self::assertSame(AuthAttempt::OUTCOME_SUCCESS, $this->attempts->lastOutcome());
        self::assertSame(AuthFailureReason::Ok, $this->attempts->lastReason());
        self::assertSame([1], $this->keys->touched, 'last_used_at stamped on first use');
    }

    #[Test]
    public function throttlesTheLastUsedWrite(): void
    {
        $this->seedActiveClientWithKey();

        $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta());
        $this->clock->advanceSeconds(120); // < 300
        $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta());

        self::assertSame([1], $this->keys->touched, 'second call within the window does not re-stamp');

        $this->clock->advanceSeconds(240); // now 360s past the first stamp
        $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta());

        self::assertSame([1, 1], $this->keys->touched);
    }

    #[Test]
    public function missingHeaderIsUnauthorized(): void
    {
        $result = $this->authenticator->authenticate(null, $this->meta());

        self::assertFalse($result->ok);
        self::assertSame(401, $result->failureStatus);
        self::assertSame(AuthFailureReason::MissingAuthorization, $this->attempts->lastReason());
        self::assertNull($this->attempts->last()->keyId);
    }

    #[Test]
    public function malformedTokenIsUnauthorized(): void
    {
        $result = $this->authenticator->authenticate('Bearer not-a-token', $this->meta());

        self::assertFalse($result->ok);
        self::assertSame(AuthFailureReason::MalformedToken, $this->attempts->lastReason());
    }

    #[Test]
    public function unknownKeyIsUnauthorized(): void
    {
        $result = $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta());

        self::assertFalse($result->ok);
        self::assertSame(AuthFailureReason::UnknownKey, $this->attempts->lastReason());
        self::assertSame(self::KEY_ID, $this->attempts->last()->keyId);
    }

    #[Test]
    public function prefixMismatchIsUnauthorized(): void
    {
        $this->seedActiveClientWithKey(ApiKeyPrefix::Test); // stored gk_test, token says gk_live

        $result = $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta());

        self::assertFalse($result->ok);
        self::assertSame(AuthFailureReason::UnknownKey, $this->attempts->lastReason());
    }

    #[Test]
    public function wrongSecretIsUnauthorized(): void
    {
        $this->seedActiveClientWithKey();

        $result = $this->authenticator->authenticate('Bearer gk_live_0123456789abcdef.wrongsecretwrongsecret', $this->meta());

        self::assertFalse($result->ok);
        self::assertSame(AuthFailureReason::InvalidSecret, $this->attempts->lastReason());
    }

    #[Test]
    public function revokedKeyIsUnauthorized(): void
    {
        $key = $this->seedActiveClientWithKey();
        $key->revoke(1, $this->clock->now());
        $this->keys->save($key);

        $result = $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta());

        self::assertFalse($result->ok);
        self::assertSame(AuthFailureReason::KeyRevoked, $this->attempts->lastReason());
    }

    #[Test]
    public function expiredKeyIsUnauthorized(): void
    {
        $this->clients->add(new ClientSnapshot(7, 'televika', 'Televika', ClientStatus::Active, 'EUR', 'DE', 'UTC'));
        $key = ClientApiKey::issue(7, self::KEY_ID, hash('sha256', self::SECRET), ApiKeyPrefix::Live, 't123', null, $this->clock->now(), $this->clock->now()->modify('-1 second'));
        $this->keys->save($key);

        $result = $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta());

        self::assertFalse($result->ok);
        self::assertSame(AuthFailureReason::KeyExpired, $this->attempts->lastReason());
    }

    #[Test]
    public function disabledClientIsForbidden(): void
    {
        $this->clients->add(new ClientSnapshot(7, 'televika', 'Televika', ClientStatus::Disabled, 'EUR', 'DE', 'UTC'));
        $this->keys->save(ClientApiKey::issue(7, self::KEY_ID, hash('sha256', self::SECRET), ApiKeyPrefix::Live, 't123', null, $this->clock->now()));

        $result = $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta());

        self::assertFalse($result->ok);
        self::assertSame(403, $result->failureStatus);
        self::assertSame('client_disabled', $result->failureCode);
        self::assertSame(AuthFailureReason::ClientDisabled, $this->attempts->lastReason());
        self::assertSame(7, $this->attempts->last()->clientId);
    }

    #[Test]
    public function locksOutAKeyIdAfterFiveFailedAttemptsWithinTheWindow(): void
    {
        $this->seedActiveClientWithKey();
        $wrongToken = 'Bearer gk_live_0123456789abcdef.wrongsecretwrongsecret';

        for ($i = 0; $i < 5; ++$i) {
            $result = $this->authenticator->authenticate($wrongToken, $this->meta());
            self::assertFalse($result->ok);
            self::assertSame(AuthFailureReason::InvalidSecret, $this->attempts->lastReason());
        }

        // 6th attempt, even with the CORRECT secret, is locked out.
        $result = $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta());

        self::assertFalse($result->ok);
        self::assertSame(429, $result->failureStatus);
        self::assertSame('rate_limited', $result->failureCode);
        self::assertSame(900, $result->retryAfterSeconds);
        self::assertSame(AuthFailureReason::TooManyAttempts, $this->attempts->lastReason());
        self::assertSame(self::KEY_ID, $this->attempts->last()->keyId);
    }

    #[Test]
    public function lockoutClearsOnceTheWindowPasses(): void
    {
        $this->seedActiveClientWithKey();
        $wrongToken = 'Bearer gk_live_0123456789abcdef.wrongsecretwrongsecret';

        for ($i = 0; $i < 5; ++$i) {
            $this->authenticator->authenticate($wrongToken, $this->meta());
        }
        self::assertSame(429, $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta())->failureStatus);

        $this->clock->advanceSeconds(16 * 60); // past the 15-minute window

        $result = $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta());

        self::assertTrue($result->ok, 'a correct credential authenticates again once the window has passed');
    }

    #[Test]
    public function lockoutAppliesPerKeyIdNotGlobally(): void
    {
        $this->seedActiveClientWithKey();
        $this->clients->add(new ClientSnapshot(8, 'other-client', 'Other Client', ClientStatus::Active, 'EUR', 'DE', 'UTC'));
        $otherKeyId = 'fedcba9876543210';
        $otherToken = 'gk_live_' . $otherKeyId . '.othersecretothersecret';
        $this->keys->save(ClientApiKey::issue(8, $otherKeyId, hash('sha256', 'othersecretothersecret'), ApiKeyPrefix::Live, 't456', null, $this->clock->now()));

        $wrongToken = 'Bearer gk_live_0123456789abcdef.wrongsecretwrongsecret';
        for ($i = 0; $i < 5; ++$i) {
            $this->authenticator->authenticate($wrongToken, $this->meta());
        }
        self::assertSame(429, $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta())->failureStatus);

        $result = $this->authenticator->authenticate('Bearer ' . $otherToken, $this->meta());

        self::assertTrue($result->ok, 'a different key_id is unaffected by another key_id\'s lockout');
    }

    #[Test]
    public function beingHitWhileLockedOutKeepsTheLockoutFromExpiring(): void
    {
        $this->seedActiveClientWithKey();
        $wrongToken = 'Bearer gk_live_0123456789abcdef.wrongsecretwrongsecret';

        for ($i = 0; $i < 5; ++$i) {
            $this->authenticator->authenticate($wrongToken, $this->meta());
        }

        // Attacker keeps hitting the locked-out key_id every minute, each
        // attempt itself recorded as a failure — same self-perpetuating
        // behavior AuthenticateAdminHandler already relies on.
        for ($i = 0; $i < 10; ++$i) {
            $this->clock->advanceSeconds(60);
            $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta());
        }

        $result = $this->authenticator->authenticate('Bearer ' . self::TOKEN, $this->meta());

        self::assertFalse($result->ok, 'continuing to hit the endpoint keeps refreshing the lockout window');
        self::assertSame(429, $result->failureStatus);
    }

    private function seedActiveClientWithKey(ApiKeyPrefix $prefix = ApiKeyPrefix::Live): ClientApiKey
    {
        $this->clients->add(new ClientSnapshot(7, 'televika', 'Televika', ClientStatus::Active, 'EUR', 'DE', 'UTC'));
        $key = ClientApiKey::issue(7, self::KEY_ID, hash('sha256', self::SECRET), $prefix, 't123', 'primary', $this->clock->now());
        $this->keys->save($key);

        self::assertSame(ApiKeyStatus::Active, $key->status());

        return $key;
    }

    private function meta(): AuthRequestMeta
    {
        return new AuthRequestMeta(ip: '203.0.113.4', userAgent: 'phpunit', correlationId: 'corr-1');
    }
}
