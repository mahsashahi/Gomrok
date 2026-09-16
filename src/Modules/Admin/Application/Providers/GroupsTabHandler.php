<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Providers;

use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountSummary;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupAccount;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;

/**
 * Builds the Providers screen's "By-groups" tab (Phase 27) — a master-detail
 * over {@see ProviderGroupRepository}, with a "resolved-provider readout" per
 * group: the ordered account chain, which one would be chosen first, and why
 * an earlier one was skipped — the two context-free checks
 * {@see \Gomrok\Modules\Providers\Application\Routing\ProviderRouter} applies
 * before anything request-specific (link enabled, account active). Full
 * per-request routing also filters by mode/country/purchase-type/method,
 * which this static screen has no single request to simulate.
 */
final readonly class GroupsTabHandler
{
    public function __construct(
        private ProviderGroupRepository $groups,
        private ProviderAccountDirectory $accounts,
    ) {
    }

    public function forClient(int $clientId, ?string $selectedSlug): GroupsTabResult
    {
        $groups = $this->groups->forClient($clientId);

        $selectedGroup = null;
        foreach ($groups as $group) {
            if ($group->slug()->value === $selectedSlug) {
                $selectedGroup = $group;

                break;
            }
        }
        $selectedGroup ??= $groups[0] ?? null;

        $items = array_map(
            fn (ProviderGroup $g): GroupListItem => new GroupListItem(
                $g->slug()->value,
                $g->name(),
                $g->isDefault() ? 'All other countries' : implode(', ', $g->countryCodes()),
                $g->isDefault(),
                $selectedGroup !== null && $g->slug()->value === $selectedGroup->slug()->value,
            ),
            $groups,
        );

        $detail = $selectedGroup !== null ? $this->buildDetail($selectedGroup, $clientId) : null;

        return new GroupsTabResult($items, $detail);
    }

    private function buildDetail(ProviderGroup $group, int $clientId): GroupDetail
    {
        $groupId = $group->id();
        \assert($groupId !== null);

        $accountsById = [];
        foreach ($this->accounts->forClient($clientId) as $summary) {
            $accountsById[$summary->id] = $summary;
        }

        $chosen = false;
        $rows = [];
        foreach ($group->accounts() as $entry) {
            $summary = $accountsById[$entry->providerAccountId()] ?? null;
            [$status, $label] = $this->resolve($entry, $summary, $chosen);
            if ($status === 'chosen' || $status === 'fallback') {
                $chosen = true;
            }

            $rows[] = new GroupAccountRow(
                $entry->providerAccountId(),
                $summary !== null ? $summary->slug : "#{$entry->providerAccountId()}",
                $summary !== null ? $summary->name : "#{$entry->providerAccountId()}",
                $summary !== null ? $summary->providerTypeCode : '—',
                $summary !== null ? $summary->mode : '—',
                $entry->priority(),
                $entry->isEnabled(),
                $summary !== null && $summary->isActive(),
                $status,
                $label,
            );
        }

        return new GroupDetail(
            $groupId,
            $group->slug()->value,
            $group->name(),
            $group->isDefault(),
            $group->isActive(),
            $group->deviceType()?->value,
            $group->currencyCode(),
            $group->isDefault() ? 'All other countries' : implode(', ', $group->countryCodes()),
            $group->countryCodes(),
            array_map(static fn ($pt): string => self::label($pt->value), $group->purchaseTypes()),
            array_map(static fn ($pt): string => $pt->value, $group->purchaseTypes()),
            $group->methods() === [] ? ['Any method'] : array_map(static fn ($m): string => self::label($m->value), $group->methods()),
            array_map(static fn ($m): string => $m->value, $group->methods()),
            $rows,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function resolve(ProviderGroupAccount $entry, ?ProviderAccountSummary $summary, bool $alreadyChosen): array
    {
        if (!$entry->isEnabled()) {
            return ['skipped_link_disabled', 'Skipped — link disabled in this group'];
        }
        if ($summary === null) {
            return ['skipped_account_missing', 'Skipped — account not found'];
        }
        if (!$summary->isActive()) {
            return ['skipped_account_disabled', 'Skipped — account disabled'];
        }

        return $alreadyChosen ? ['fallback', 'Fallback'] : ['chosen', 'Chosen first'];
    }

    private static function label(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}
