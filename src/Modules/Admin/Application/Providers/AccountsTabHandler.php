<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Providers;

use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountSummary;
use Gomrok\Modules\Providers\Application\ProviderCapabilityResolver;
use Gomrok\Modules\Providers\Application\ProviderCatalog;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;

/**
 * Builds the Providers screen's "Accounts" tab (Phase 27) — a master-detail
 * over {@see ProviderAccountDirectory}, matching the read-only shape of
 * {@see \Gomrok\Modules\Admin\Application\Packaging\PackagesTabHandler}.
 * **Never touches the decrypted secret** — only `ProviderAccountSummary`'s
 * `secretLastFour` reaches this handler; the admin UI's "Reveal" toggle only
 * ever shows the last 4 digits, never the full value (Phase 27 Q2).
 */
final readonly class AccountsTabHandler
{
    public function __construct(
        private ProviderAccountDirectory $accounts,
        private ProviderCatalog $providerTypes,
        private ProviderCapabilityResolver $capabilities,
        private ProviderGroupRepository $groups,
    ) {
    }

    public function forClient(int $clientId, ?string $selectedSlug): AccountsTabResult
    {
        $accounts = $this->accounts->forClient($clientId);
        $items = array_map(
            fn (ProviderAccountSummary $a): AccountListItem => new AccountListItem(
                $a->slug,
                $a->name,
                $a->providerTypeCode,
                $a->mode,
                $a->status,
                $a->slug === $selectedSlug,
            ),
            $accounts,
        );

        $selected = null;
        foreach ($accounts as $account) {
            if ($account->slug === $selectedSlug) {
                $selected = $account;

                break;
            }
        }
        $selected ??= $accounts[0] ?? null;

        $detail = $selected !== null ? $this->buildDetail($selected, $clientId) : null;

        return new AccountsTabResult($items, $detail);
    }

    private function buildDetail(ProviderAccountSummary $account, int $clientId): AccountDetail
    {
        $providerType = $this->providerTypes->find($account->providerTypeCode);
        $resolved = $this->capabilities->resolve($account->providerTypeCode);

        $capabilitiesDisplay = $resolved !== null
            ? array_map(static fn ($c): string => $c->label(), $resolved->capabilities->all())
            : [];
        $purchaseTypesDisplay = $resolved !== null
            ? array_map(static fn ($pt): string => self::label($pt->value), $resolved->purchaseTypes)
            : [];

        $memberships = [];
        foreach ($this->groups->forClient($clientId) as $group) {
            $membership = $this->membershipIn($group, $account->id);
            if ($membership !== null) {
                $memberships[] = $membership;
            }
        }

        return new AccountDetail(
            $account->id,
            $account->slug,
            $account->name,
            $account->providerTypeCode,
            $providerType !== null ? $providerType->name : $account->providerTypeCode,
            $account->mode,
            $account->status,
            $account->isActive(),
            $account->publicKey,
            $account->secretLastFour,
            $account->countries === [] ? 'Everywhere' : implode(', ', $account->countries),
            $account->countries,
            $account->methods === [] ? 'Any method' : implode(', ', array_map(self::label(...), $account->methods)),
            $account->methods,
            $account->activeEndpointCount,
            $capabilitiesDisplay,
            $purchaseTypesDisplay,
            $memberships,
        );
    }

    private function membershipIn(ProviderGroup $group, int $accountId): ?AccountGroupMembership
    {
        foreach ($group->accounts() as $entry) {
            if ($entry->providerAccountId() === $accountId) {
                return new AccountGroupMembership($group->slug()->value, $group->name(), $entry->priority(), $entry->isEnabled());
            }
        }

        return null;
    }

    private static function label(string $value): string
    {
        return ucwords(str_replace('_', ' ', $value));
    }
}
