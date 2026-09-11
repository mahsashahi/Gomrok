<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Routing;

use LogicException;

/**
 * The outcome of a successful routing resolution: the resolved group, the
 * ordered candidate accounts (chosen = first), and why every other account was
 * dropped. Serialise it with {@see toArray()} to snapshot on a checkout attempt
 * (Phase 18's `provider_routing_decision_snapshots`, via
 * {@see ProviderRoutingDecisionSnapshot}); {@see fromArray()} rebuilds a
 * read-only view with no repository lookups.
 *
 * @phpstan-type DecisionArray array{
 *     version: int,
 *     client_id: int,
 *     country: string,
 *     currency: string,
 *     purchase_type: string,
 *     payment_method: string|null,
 *     mode: string,
 *     device_type: string|null,
 *     group: array{id: int, slug: string, is_default: bool},
 *     chosen_account_id: int|null,
 *     candidates: list<array{account_id: int, slug: string, provider_type_code: string, mode: string, priority: int}>,
 *     rejections: list<array{account_id: int, slug: string, reason: string}>
 * }
 */
final readonly class RoutingDecision
{
    public const VERSION = 1;

    /**
     * @param list<RoutedAccount>   $candidates ordered by priority; non-empty
     * @param list<RejectedAccount> $rejections
     */
    public function __construct(
        public int $clientId,
        public string $country,
        public string $currency,
        public string $purchaseType,
        public ?string $paymentMethod,
        public string $mode,
        public ?string $deviceType,
        public int $groupId,
        public string $groupSlug,
        public bool $groupIsDefault,
        public array $candidates,
        public array $rejections,
    ) {
    }

    public function chosen(): RoutedAccount
    {
        return $this->candidates[0] ?? throw new LogicException('RoutingDecision has no candidate account.');
    }

    /**
     * @return DecisionArray
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'client_id' => $this->clientId,
            'country' => $this->country,
            'currency' => $this->currency,
            'purchase_type' => $this->purchaseType,
            'payment_method' => $this->paymentMethod,
            'mode' => $this->mode,
            'device_type' => $this->deviceType,
            'group' => [
                'id' => $this->groupId,
                'slug' => $this->groupSlug,
                'is_default' => $this->groupIsDefault,
            ],
            'chosen_account_id' => $this->candidates[0]->accountId ?? null,
            'candidates' => array_map(
                static fn (RoutedAccount $a): array => [
                    'account_id' => $a->accountId,
                    'slug' => $a->slug,
                    'provider_type_code' => $a->providerTypeCode,
                    'mode' => $a->mode,
                    'priority' => $a->priority,
                ],
                $this->candidates,
            ),
            'rejections' => array_map(
                static fn (RejectedAccount $r): array => [
                    'account_id' => $r->accountId,
                    'slug' => $r->slug,
                    'reason' => $r->reason->value,
                ],
                $this->rejections,
            ),
        ];
    }

    /**
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $group = self::arr($data['group'] ?? null);
        $candidates = [];
        foreach (self::listOfArrays($data['candidates'] ?? null) as $row) {
            $candidates[] = new RoutedAccount(
                self::int($row['account_id'] ?? null),
                self::str($row['slug'] ?? null),
                self::str($row['provider_type_code'] ?? null),
                self::str($row['mode'] ?? null),
                self::int($row['priority'] ?? null),
            );
        }

        $rejections = [];
        foreach (self::listOfArrays($data['rejections'] ?? null) as $row) {
            $reason = RejectionReason::tryFrom(self::str($row['reason'] ?? null));
            if ($reason === null) {
                continue;
            }
            $rejections[] = new RejectedAccount(
                self::int($row['account_id'] ?? null),
                self::str($row['slug'] ?? null),
                $reason,
            );
        }

        return new self(
            self::int($data['client_id'] ?? null),
            self::str($data['country'] ?? null),
            self::str($data['currency'] ?? null),
            self::str($data['purchase_type'] ?? null),
            self::nullableStr($data['payment_method'] ?? null),
            self::str($data['mode'] ?? null),
            self::nullableStr($data['device_type'] ?? null),
            self::int($group['id'] ?? null),
            self::str($group['slug'] ?? null),
            self::bool($group['is_default'] ?? null),
            $candidates,
            $rejections,
        );
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function arr(mixed $data): array
    {
        return \is_array($data) ? $data : [];
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private static function listOfArrays(mixed $data): array
    {
        if (!\is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $row) {
            if (\is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    private static function str(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function nullableStr(mixed $value): ?string
    {
        return $value === null ? null : self::str($value);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function bool(mixed $value): bool
    {
        return \is_scalar($value) && (bool) $value;
    }
}
