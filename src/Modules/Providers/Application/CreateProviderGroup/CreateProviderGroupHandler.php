<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\CreateProviderGroup;

use Gomrok\Modules\Clients\Application\ClientDirectory;
use Gomrok\Modules\Providers\Application\ProviderGroupAuditSnapshot;
use Gomrok\Modules\Providers\Domain\DeviceType;
use Gomrok\Modules\Providers\Domain\ProviderGroup;
use Gomrok\Modules\Providers\Domain\ProviderGroupRepository;
use Gomrok\Modules\Providers\Domain\ProviderGroupSlug;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\ReferenceCatalog;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;
use ValueError;

/**
 * Creates a provider group: validates the client, device type, currency and
 * default-uniqueness, persists, audits.
 */
final readonly class CreateProviderGroupHandler
{
    public function __construct(
        private ProviderGroupRepository $groups,
        private ClientDirectory $clients,
        private ReferenceCatalog $reference,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(CreateProviderGroupCommand $command): Result
    {
        $deviceType = null;
        if ($command->deviceType !== null && $command->deviceType !== '') {
            try {
                $deviceType = DeviceType::from($command->deviceType);
            } catch (ValueError) {
                return Result::err(DomainError::validation(
                    'provider_group.invalid_device_type',
                    'Device type must be one of web, ios, android.',
                    ['device_type' => $command->deviceType],
                ));
            }
        }

        $name = trim($command->name);
        if ($name === '') {
            return Result::err(DomainError::validation('provider_group.name_required', 'A name is required.'));
        }

        $slugValue = $command->slug !== null && $command->slug !== ''
            ? $command->slug
            : self::slugify($name);
        if (!ProviderGroupSlug::isValid($slugValue)) {
            return Result::err(ProviderGroupSlug::error($slugValue));
        }

        $currency = null;
        if ($command->currencyCode !== null && $command->currencyCode !== '') {
            $currency = strtoupper($command->currencyCode);
            if (!$this->reference->currencyExists($currency)) {
                return Result::err(DomainError::validation(
                    'provider_group.unknown_currency',
                    "Currency '{$currency}' is not configured.",
                    ['currency' => $currency],
                ));
            }
        }

        $client = $this->clients->findById($command->clientId);
        if ($client === null) {
            return Result::err(DomainError::notFound('client.not_found', "Client {$command->clientId} was not found."));
        }
        if (!$client->isActive()) {
            return Result::err(DomainError::forbidden('client.disabled', 'Cannot add a provider group to a disabled client.'));
        }

        if ($this->groups->existsForClientWithSlug($command->clientId, $slugValue)) {
            return Result::err(DomainError::conflict(
                'provider_group.slug_taken',
                "This client already has a provider group '{$slugValue}'.",
                ['slug' => $slugValue],
            ));
        }

        if ($command->isDefault) {
            foreach ($this->groups->forClient($command->clientId) as $existing) {
                if ($existing->isDefault() && $existing->deviceType() === $deviceType) {
                    return Result::err(DomainError::conflict(
                        'provider_group.default_exists',
                        \sprintf(
                            "This client already has a default group%s ('%s').",
                            $deviceType !== null ? " for device '{$deviceType->value}'" : '',
                            $existing->slug()->value,
                        ),
                        ['device_type' => $deviceType?->value, 'existing' => $existing->slug()->value],
                    ));
                }
            }
        }

        $now = $this->clock->now();
        $group = ProviderGroup::define(
            $command->clientId,
            ProviderGroupSlug::of($slugValue),
            $name,
            $command->isDefault,
            $deviceType,
            $currency,
            $now,
        );

        $this->transactions->run(function () use ($group, $command): void {
            $this->groups->save($group);
            $groupId = $group->id();
            \assert($groupId !== null);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $command->clientId, 'provider_group.created')
                : AuditEntry::forSystem('provider_group.created', $command->clientId);

            $this->audit->record(
                $entry
                    ->withTarget('provider_group', $groupId)
                    ->withChange(null, ProviderGroupAuditSnapshot::of($group)),
            );
        });

        $groupId = $group->id();
        \assert($groupId !== null);

        return Result::ok(new CreateProviderGroupResult($groupId, $slugValue, $command->isDefault));
    }

    private static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
