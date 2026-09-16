<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Customers;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Packages\Application\PackageDirectory;
use Gomrok\Modules\Payments\Application\PaymentDirectory;
use Gomrok\Modules\Payments\Application\PaymentSummary;
use Gomrok\Modules\Payments\Domain\ProviderCustomer;
use Gomrok\Modules\Payments\Domain\ProviderCustomerRepository;
use Gomrok\Modules\Providers\Application\ProviderAccountDirectory;
use Gomrok\Modules\Subscriptions\Application\SubscriptionDirectory;
use Gomrok\Modules\Subscriptions\Application\SubscriptionSummary;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\Money;

/**
 * Builds the admin panel's Customers screen (Phase 27) — one row per
 * distinct `client_user_ref` seen across a client's payments and
 * subscriptions. Gomrok has no first-class `customers` table (the design
 * mockup's "OURS" internal customer id doesn't correspond to anything Gomrok
 * actually stores — only the "CLIENT" row, `client_user_ref`, is real, so
 * that's the only identity shown); the design's mobile/email search is
 * likewise adapted to a `client_user_ref` substring match, since Gomrok
 * never collects contact details itself. "Provider references" are real
 * {@see \Gomrok\Modules\Payments\Domain\ProviderCustomer} rows (Phase 20 Q4)
 * — a durable per-provider customer identity, distinct from a one-off
 * {@see \Gomrok\Modules\Payments\Domain\GatewayReference}.
 */
final readonly class CustomersListHandler
{
    public function __construct(
        private ClientDirectory $clients,
        private PaymentDirectory $payments,
        private SubscriptionDirectory $subscriptions,
        private ProviderCustomerRepository $providerCustomers,
        private ProviderAccountDirectory $providerAccounts,
        private PackageDirectory $packages,
    ) {
    }

    public function forClient(int $clientId, ?string $query): CustomersListResult
    {
        $client = $this->clients->findById($clientId);
        $currencyCode = $client !== null ? $client->defaultCurrency : 'USD';

        $allPayments = $this->payments->forClient($clientId);
        $allSubscriptions = $this->subscriptions->forClient($clientId);

        $refs = [];
        foreach ($allPayments as $payment) {
            if ($payment->clientUserRef !== null) {
                $refs[$payment->clientUserRef] = true;
            }
        }
        foreach ($allSubscriptions as $subscription) {
            $refs[$subscription->clientUserRef] = true;
        }

        $rows = [];
        foreach (array_keys($refs) as $ref) {
            $rows[] = $this->buildRow($clientId, $ref, $allPayments, $allSubscriptions, $currencyCode);
        }

        usort($rows, static fn (CustomerRow $a, CustomerRow $b): int => $b->joined <=> $a->joined);

        $trimmedQuery = $query !== null ? trim($query) : '';
        if ($trimmedQuery !== '') {
            $needle = mb_strtolower($trimmedQuery);
            $rows = array_values(array_filter(
                $rows,
                static fn (CustomerRow $r): bool => str_contains(mb_strtolower($r->clientUserRef), $needle),
            ));
        }

        return new CustomersListResult($rows, \count($rows));
    }

    /**
     * @param list<PaymentSummary>      $allPayments
     * @param list<SubscriptionSummary> $allSubscriptions
     */
    private function buildRow(int $clientId, string $ref, array $allPayments, array $allSubscriptions, string $currencyCode): CustomerRow
    {
        $userPayments = array_values(array_filter($allPayments, static fn (PaymentSummary $p): bool => $p->clientUserRef === $ref));
        $userSubscriptions = array_values(array_filter($allSubscriptions, static fn (SubscriptionSummary $s): bool => $s->clientUserRef === $ref));

        $timestamps = array_merge(
            array_map(static fn (PaymentSummary $p): string => $p->createdAt, $userPayments),
            array_map(static fn (SubscriptionSummary $s): string => $s->createdAt, $userSubscriptions),
        );
        sort($timestamps);
        $joined = $timestamps[0] ?? '';

        $currency = Currency::of($currencyCode);
        $lifetime = Money::zero($currency);
        foreach ($userPayments as $payment) {
            if ($payment->status === 'paid' && strtoupper($payment->currencyCode) === $currency->code()) {
                $lifetime = $lifetime->plus(Money::fromMinor($payment->amountMinor, $currency));
            }
        }

        $activeCount = \count(array_filter(
            $userSubscriptions,
            static fn (SubscriptionSummary $s): bool => \in_array($s->status, ['active', 'trialing'], true),
        ));

        $providerRefs = array_map(
            fn (ProviderCustomer $pc): CustomerProviderRef => new CustomerProviderRef($this->providerAccountName($pc->providerAccountId()), $pc->providerCustomerId()),
            $this->providerCustomers->forClientUser($clientId, $ref),
        );

        $subscriptionRows = array_map(fn (SubscriptionSummary $s): CustomerSubscriptionRow => $this->toSubscriptionRow($s), $userSubscriptions);

        return new CustomerRow(
            $ref,
            $joined,
            \count($userSubscriptions),
            $activeCount,
            $lifetime->format('en_US'),
            $activeCount > 0 ? 'Active' : 'Customer',
            $providerRefs,
            $subscriptionRows,
        );
    }

    private function toSubscriptionRow(SubscriptionSummary $subscription): CustomerSubscriptionRow
    {
        $package = $this->packages->findById($subscription->packageId);
        $account = $this->providerAccounts->findById($subscription->providerAccountId);

        $renewLabel = null;
        if ($subscription->status === 'trialing' && $subscription->trialEndsAt !== null) {
            $renewLabel = 'Trial ends ' . $subscription->trialEndsAt;
        } elseif ($subscription->currentPeriodEnd !== null) {
            $renewLabel = 'Renews ' . $subscription->currentPeriodEnd;
        }

        return new CustomerSubscriptionRow(
            $subscription->id,
            $package !== null ? $package->name : "Package #{$subscription->packageId}",
            Money::fromMinor($subscription->amountMinor, Currency::of($subscription->currencyCode))->format('en_US'),
            $account !== null ? $account->name : '—',
            ucfirst(str_replace('_', ' ', $subscription->status)),
            $renewLabel,
        );
    }

    private function providerAccountName(int $providerAccountId): string
    {
        $account = $this->providerAccounts->findById($providerAccountId);

        return $account !== null ? $account->name : '—';
    }
}
