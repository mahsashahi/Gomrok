<?php

declare(strict_types=1);

namespace Gomrok\Modules\Clients\Application\Authenticate;

use DateTimeImmutable;
use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Clients\Domain\ApiKeyStatus;
use Gomrok\Modules\Clients\Domain\ApiKeyToken;
use Gomrok\Modules\Clients\Domain\AuthFailureReason;
use Gomrok\Modules\Clients\Domain\ClientApiKey;
use Gomrok\Modules\Clients\Domain\ClientApiKeyRepository;
use Gomrok\Shared\Http\AuthenticatedClient;
use Gomrok\Shared\Http\AuthRequestMeta;
use Gomrok\Shared\Http\AuthResult;
use Gomrok\Shared\Http\ClientAuthenticator;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The Clients-module implementation of {@see ClientAuthenticator}: parse the
 * bearer token, look the key up by `key_id`, constant-time compare the secret,
 * check key status/expiry and client status, throttle `last_used_at`, and record
 * the attempt.
 */
final readonly class ApiKeyAuthenticator implements ClientAuthenticator
{
    /** Skip the `last_used_at` write unless the stored value is older than this (Phase 7 Q3). */
    private const LAST_USED_THROTTLE_SECONDS = 300;

    public function __construct(
        private ClientApiKeyRepository $apiKeys,
        private ClientDirectory $clients,
        private AuthAttemptLog $attempts,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function authenticate(?string $authorizationHeader, AuthRequestMeta $meta): AuthResult
    {
        $now = $this->clock->now();

        $token = $this->bearerToken($authorizationHeader);
        if ($token === null) {
            return $this->deny(AuthFailureReason::MissingAuthorization, null, null, $meta, $now);
        }

        $parsed = ApiKeyToken::parse($token);
        if ($parsed === null) {
            return $this->deny(AuthFailureReason::MalformedToken, null, null, $meta, $now);
        }

        $key = $this->apiKeys->findByKeyId($parsed->keyId);
        if ($key === null || $key->prefix() !== $parsed->prefix) {
            return $this->deny(AuthFailureReason::UnknownKey, $parsed->keyId, $key?->clientId(), $meta, $now);
        }

        if (!$key->matchesSecret($parsed->secret)) {
            return $this->deny(AuthFailureReason::InvalidSecret, $parsed->keyId, $key->clientId(), $meta, $now);
        }

        if ($key->status() === ApiKeyStatus::Revoked) {
            return $this->deny(AuthFailureReason::KeyRevoked, $parsed->keyId, $key->clientId(), $meta, $now);
        }

        if (!$key->isUsable($now)) {
            return $this->deny(AuthFailureReason::KeyExpired, $parsed->keyId, $key->clientId(), $meta, $now);
        }

        $client = $this->clients->findById($key->clientId());
        if ($client === null) {
            return $this->deny(AuthFailureReason::UnknownKey, $parsed->keyId, $key->clientId(), $meta, $now);
        }

        if (!$client->isActive()) {
            $this->safeRecord(AuthAttempt::failure(AuthFailureReason::ClientDisabled, $parsed->keyId, $client->id, $meta), $now);

            return AuthResult::clientDisabled();
        }

        $this->throttleLastUsed($key, $now);
        $this->safeRecord(AuthAttempt::success($parsed->keyId, $client->id, $meta), $now);

        return AuthResult::success(new AuthenticatedClient(
            $client->id,
            $client->slug,
            $client->name,
            $client->status->value,
            $client->defaultCurrency,
            $client->defaultCountry,
            $client->timezone,
            $key->prefix()->value,
        ));
    }

    private function bearerToken(?string $header): ?string
    {
        if ($header === null || preg_match('/^Bearer\s+(\S.*)$/i', trim($header), $m) !== 1) {
            return null;
        }

        return trim($m[1]);
    }

    private function deny(
        AuthFailureReason $reason,
        ?string $keyId,
        ?int $clientId,
        AuthRequestMeta $meta,
        DateTimeImmutable $now,
    ): AuthResult {
        $this->safeRecord(AuthAttempt::failure($reason, $keyId, $clientId, $meta), $now);

        return AuthResult::unauthorized();
    }

    private function throttleLastUsed(ClientApiKey $key, DateTimeImmutable $now): void
    {
        $lastUsed = $key->lastUsedAt();
        if ($lastUsed !== null && $lastUsed > $now->modify('-' . self::LAST_USED_THROTTLE_SECONDS . ' seconds')) {
            return;
        }

        $id = $key->id();
        if ($id === null) {
            return;
        }

        try {
            $this->apiKeys->touchLastUsed($id, $now);
        } catch (Throwable $e) {
            $this->logger->warning('auth.last_used_touch_failed', ['key_id' => $key->keyId(), 'reason' => $e->getMessage()]);
        }
    }

    private function safeRecord(AuthAttempt $attempt, DateTimeImmutable $now): void
    {
        try {
            $this->attempts->record($attempt, $now);
        } catch (Throwable $e) {
            $this->logger->warning('auth.attempt_log_failed', ['reason' => $e->getMessage()]);
        }
    }
}
