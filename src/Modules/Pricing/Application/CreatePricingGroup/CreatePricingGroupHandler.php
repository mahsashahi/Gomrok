<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\CreatePricingGroup;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Pricing\Application\PriceListAuditSnapshot;
use Gomrok\Modules\Pricing\Application\PricingAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\PriceList;
use Gomrok\Modules\Pricing\Domain\PriceListRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroup;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\Currency;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;

/**
 * Creates a pricing group: validates the client, slug, currency, device type,
 * priority uniqueness and one-default-per-client.
 */
final readonly class CreatePricingGroupHandler
{
    private const DEVICE_TYPES = ['web', 'ios', 'android'];

    public function __construct(
        private PricingGroupRepository $groups,
        private PriceListRepository $priceLists,
        private ClientDirectory $clients,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(CreatePricingGroupCommand $command): Result
    {
        $deviceType = null;
        if ($command->deviceType !== null && $command->deviceType !== '') {
            $deviceType = strtolower($command->deviceType);
            if (!\in_array($deviceType, self::DEVICE_TYPES, true)) {
                return Result::err(DomainError::validation('pricing_group.invalid_device_type', 'Device type must be one of web, ios, android.', ['device_type' => $command->deviceType]));
            }
        }

        $name = trim($command->name);
        if ($name === '') {
            return Result::err(DomainError::validation('pricing_group.name_required', 'A name is required.'));
        }

        $slugValue = $command->slug !== null && $command->slug !== '' ? $command->slug : self::slugify($name);
        if (!PricingGroupSlug::isValid($slugValue)) {
            return Result::err(PricingGroupSlug::error($slugValue));
        }

        try {
            $currency = Currency::of($command->currency)->code();
        } catch (InvalidArgumentException) {
            return Result::err(DomainError::validation('pricing_group.invalid_currency', "'{$command->currency}' is not a valid ISO 4217 currency.", ['currency' => $command->currency]));
        }
        if (!$this->reference->currencyExists($currency)) {
            return Result::err(DomainError::validation('pricing_group.unknown_currency', "Currency '{$currency}' is not configured.", ['currency' => $currency]));
        }

        $client = $this->clients->findById($command->clientId);
        if ($client === null) {
            return Result::err(DomainError::notFound('client.not_found', "Client {$command->clientId} was not found."));
        }
        if (!$client->isActive()) {
            return Result::err(DomainError::forbidden('client.disabled', 'Cannot add a pricing group to a disabled client.'));
        }

        if ($this->groups->existsForClientWithSlug($command->clientId, $slugValue)) {
            return Result::err(DomainError::conflict('pricing_group.slug_taken', "This client already has a pricing group '{$slugValue}'.", ['slug' => $slugValue]));
        }

        $existing = $this->groups->forClient($command->clientId);
        foreach ($existing as $group) {
            if ($command->isDefault && $group->isDefault()) {
                return Result::err(DomainError::conflict('pricing_group.default_exists', "This client already has a default pricing group ('{$group->slug()->value}').", ['existing' => $group->slug()->value]));
            }
            if (!$command->isDefault && !$group->isDefault() && $group->priority() === max(0, $command->priority)) {
                return Result::err(DomainError::conflict('pricing_group.priority_taken', "Priority {$command->priority} is already used by group '{$group->slug()->value}'.", ['priority' => $command->priority, 'existing' => $group->slug()->value]));
            }
        }

        $priority = $command->isDefault ? 0 : max(0, $command->priority);
        $now = $this->clock->now();
        $group = PricingGroup::define(
            $command->clientId,
            PricingGroupSlug::of($slugValue),
            $name,
            $priority,
            $deviceType,
            $currency,
            $command->isDefault,
            $now,
        );

        $this->transactions->run(function () use ($group, $command, $now): void {
            $this->groups->save($group);
            $groupId = $group->id();
            \assert($groupId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'pricing_group.created')
                : AuditEntry::forSystem('pricing_group.created', $command->clientId);

            $this->audit->record($entry->withTarget('pricing_group', $groupId)->withChange(null, PricingAuditSnapshot::group($group)));

            // Every pricing group owns a control price list (Phase 15).
            $control = PriceList::control($command->clientId, $groupId, $now);
            $this->priceLists->save($control);
            $controlId = $control->id();
            \assert($controlId !== null);

            $controlEntry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'price_list.created')
                : AuditEntry::forSystem('price_list.created', $command->clientId);

            $this->audit->record(
                $controlEntry->withTarget('price_list', $controlId)
                    ->withChange(null, PriceListAuditSnapshot::list($control))
                    ->withContext(['pricing_group_id' => $groupId]),
            );
        });

        $groupId = $group->id();
        \assert($groupId !== null);

        return Result::ok(new CreatePricingGroupResult($groupId, $slugValue, $command->isDefault));
    }

    private static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
