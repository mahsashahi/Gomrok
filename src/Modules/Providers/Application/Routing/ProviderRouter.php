<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Routing;

use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Providers\Application\ProviderAccountSummary;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclarations;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Log\LoggerInterface;

/**
 * Deterministic "which provider account for this purchase" (Phase 10). Resolves
 * the client's {@see ProviderGroup} for the request country/device, then filters
 * the group's ordered accounts by mode, status, served country, the requested
 * purchase type (group set ∩ provider-type declaration) and payment method.
 *
 * Unsupported combinations are rejected with a {@see DomainError}, never
 * silently downgraded — a Turkey subscription request fails here, it does not
 * become a one-time payment.
 *
 * @phpstan-import-type DecisionArray from RoutingDecision
 */
final readonly class ProviderRouter
{
    public function __construct(
        private ProviderGroupRepository $groups,
        private ProviderAccountDirectory $accounts,
        private ProviderTypeDeclarations $declarations,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return Result ok({@see RoutingDecision}) | err({@see DomainError})
     */
    public function route(RoutingRequest $request): Result
    {
        $country = strtoupper(trim($request->country));
        $currency = strtoupper(trim($request->currency));

        $group = $this->resolveGroup($request, $country);
        if ($group === null) {
            return Result::err(DomainError::notFound(
                'provider_routing.no_group_for_market',
                "This client has no provider group for country '{$country}' and no default group.",
                ['country' => $country, 'device_type' => $request->deviceType?->value],
            ));
        }

        $groupId = $group->id();
        \assert($groupId !== null);

        if (!$group->allowsPurchaseType($request->purchaseType)) {
            return Result::err(DomainError::ruleViolation(
                'provider_routing.purchase_type_not_enabled',
                "Purchase type '{$request->purchaseType->value}' is not enabled for country '{$country}'.",
                ['country' => $country, 'purchase_type' => $request->purchaseType->value, 'group' => $group->slug()->value],
            ));
        }

        $groupCurrency = $group->currencyCode();
        if ($groupCurrency !== null && $groupCurrency !== $currency) {
            return Result::err(DomainError::ruleViolation(
                'provider_routing.currency_not_supported',
                "Country '{$country}' settles in {$groupCurrency}, not {$currency}.",
                ['country' => $country, 'requested_currency' => $currency, 'group_currency' => $groupCurrency],
            ));
        }

        if ($request->paymentMethod !== null && !$group->allowsMethod($request->paymentMethod)) {
            return Result::err(DomainError::ruleViolation(
                'provider_routing.method_not_enabled',
                "Payment method '{$request->paymentMethod->value}' is not enabled for country '{$country}'.",
                ['country' => $country, 'payment_method' => $request->paymentMethod->value, 'group' => $group->slug()->value],
            ));
        }

        $accountsById = $this->indexAccounts($request->clientId);

        $candidates = [];
        $rejections = [];
        foreach ($group->accounts() as $entry) {
            $summary = $accountsById[$entry->providerAccountId()] ?? null;
            $reason = $this->reject($entry->isEnabled(), $summary, $request, $country);

            if ($reason !== null) {
                $rejections[] = new RejectedAccount(
                    $entry->providerAccountId(),
                    $summary !== null ? $summary->slug : '',
                    $reason,
                );

                continue;
            }

            \assert($summary !== null);
            $candidates[] = new RoutedAccount(
                $summary->id,
                $summary->slug,
                $summary->providerTypeCode,
                $summary->mode,
                $entry->priority(),
            );
        }

        if ($candidates === []) {
            $encoded = json_encode(array_map(
                static fn (RejectedAccount $r): array => ['slug' => $r->slug, 'reason' => $r->reason->value],
                $rejections,
            ));
            $this->logger->warning('provider routing found no eligible account', [
                'client_id' => $request->clientId,
                'country' => $country,
                'purchase_type' => $request->purchaseType->value,
                'group' => $group->slug()->value,
                'rejected' => $encoded === false ? null : $encoded,
            ]);

            return Result::err(DomainError::unsupported(
                'provider_routing.no_provider_for_market',
                "No provider account can serve a '{$request->purchaseType->value}' payment in '{$country}'.",
                [
                    'country' => $country,
                    'purchase_type' => $request->purchaseType->value,
                    'group' => $group->slug()->value,
                    'rejected' => $encoded === false ? null : $encoded,
                ],
            ));
        }

        $decision = new RoutingDecision(
            $request->clientId,
            $country,
            $currency,
            $request->purchaseType->value,
            $request->paymentMethod?->value,
            $request->mode->value,
            $request->deviceType?->value,
            $groupId,
            $group->slug()->value,
            $group->isDefault(),
            $candidates,
            $rejections,
        );

        $this->logger->info('provider routing resolved', [
            'client_id' => $request->clientId,
            'country' => $country,
            'currency' => $currency,
            'purchase_type' => $request->purchaseType->value,
            'mode' => $request->mode->value,
            'group' => $group->slug()->value,
            'group_is_default' => $group->isDefault(),
            'chosen_account_id' => $decision->chosen()->accountId,
            'candidate_count' => \count($candidates),
            'rejected_count' => \count($rejections),
        ]);

        return Result::ok($decision);
    }

    private function resolveGroup(RoutingRequest $request, string $country): ?ProviderGroup
    {
        $clientGroups = $this->groups->forClient($request->clientId);

        $default = null;
        foreach ($clientGroups as $group) {
            if (!$group->isActive() || !$group->appliesToDevice($request->deviceType)) {
                continue;
            }
            if ($group->isDefault()) {
                $default ??= $group;

                continue;
            }
            if ($group->servesCountry($country)) {
                return $group;
            }
        }

        return $default;
    }

    /**
     * @return array<int, ProviderAccountSummary>
     */
    private function indexAccounts(int $clientId): array
    {
        $byId = [];
        foreach ($this->accounts->forClient($clientId) as $summary) {
            $byId[$summary->id] = $summary;
        }

        return $byId;
    }

    private function reject(
        bool $linkEnabled,
        ?ProviderAccountSummary $summary,
        RoutingRequest $request,
        string $country,
    ): ?RejectionReason {
        if (!$linkEnabled) {
            return RejectionReason::GroupLinkDisabled;
        }
        if ($summary === null) {
            return RejectionReason::AccountNotFound;
        }
        if (!$summary->isActive()) {
            return RejectionReason::AccountDisabled;
        }
        if ($summary->mode !== $request->mode->value) {
            return RejectionReason::ModeMismatch;
        }
        if ($summary->countries !== [] && !\in_array($country, $summary->countries, true)) {
            return RejectionReason::CountryNotServed;
        }

        $declaration = $this->declarations->findByCode($summary->providerTypeCode);
        if ($declaration === null || !$declaration->supportsPurchaseType($request->purchaseType)) {
            return RejectionReason::PurchaseTypeUnsupportedByProvider;
        }

        if (
            $request->paymentMethod !== null
            && $summary->methods !== []
            && !\in_array($request->paymentMethod->value, $summary->methods, true)
        ) {
            return RejectionReason::MethodNotSupportedByAccount;
        }

        return null;
    }
}
