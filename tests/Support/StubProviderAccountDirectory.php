<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountSummary;
use Gomrok\Modules\Providers\Domain\ProviderAccountMode;

/**
 * In-memory {@see ProviderAccountDirectory} for router / handler tests.
 */
final class StubProviderAccountDirectory implements ProviderAccountDirectory
{
    /** @var list<ProviderAccountSummary> */
    private array $summaries = [];

    /** @var array<string, int> token => account id */
    private array $webhookTokens = [];

    /**
     * @param list<string> $countries
     * @param list<string> $methods
     */
    public function add(
        int $id,
        int $clientId,
        string $slug,
        string $providerTypeCode,
        string $mode = 'test',
        string $status = 'active',
        array $countries = [],
        array $methods = [],
    ): self {
        $this->summaries[] = new ProviderAccountSummary(
            $id,
            $clientId,
            $slug,
            ucfirst($slug),
            $providerTypeCode,
            $mode,
            $status,
            null,
            '0000',
            $countries,
            $methods,
            0,
        );

        return $this;
    }

    public function withWebhookToken(int $accountId, string $token): self
    {
        $this->webhookTokens[$token] = $accountId;

        return $this;
    }

    public function forClient(int $clientId): array
    {
        return array_values(array_filter(
            $this->summaries,
            static fn (ProviderAccountSummary $s): bool => $s->clientId === $clientId,
        ));
    }

    public function find(int $clientId, string $slug): ?ProviderAccountSummary
    {
        foreach ($this->summaries as $summary) {
            if ($summary->clientId === $clientId && $summary->slug === $slug) {
                return $summary;
            }
        }

        return null;
    }

    public function findById(int $id): ?ProviderAccountSummary
    {
        foreach ($this->summaries as $summary) {
            if ($summary->id === $id) {
                return $summary;
            }
        }

        return null;
    }

    public function candidates(int $clientId, string $providerTypeCode, ProviderAccountMode $mode): array
    {
        return array_values(array_filter(
            $this->summaries,
            static fn (ProviderAccountSummary $s): bool => $s->clientId === $clientId
                && $s->providerTypeCode === $providerTypeCode
                && $s->mode === $mode->value
                && $s->status === 'active',
        ));
    }

    public function findByEndpointToken(string $token): ?ProviderAccountSummary
    {
        $accountId = $this->webhookTokens[$token] ?? null;

        return $accountId !== null ? $this->findById($accountId) : null;
    }
}
