<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\UpdatePricingGroup;

use Gomrok\Modules\Pricing\Application\PricingAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\PricingGroupRepository;
use Gomrok\Modules\Pricing\Domain\PricingGroupSlug;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;
use Psr\Clock\ClockInterface;

/**
 * Validates exactly like {@see \Gomrok\Modules\Pricing\Application\CreatePricingGroup\CreatePricingGroupHandler}
 * (device type enum, name required, slug format + per-client uniqueness,
 * priority uniqueness among non-default siblings) but excluding the group's
 * own current row from every uniqueness check against itself.
 */
final readonly class UpdatePricingGroupHandler
{
    private const DEVICE_TYPES = ['web', 'ios', 'android'];

    public function __construct(
        private PricingGroupRepository $groups,
        private AuditLogWriter $audit,
        private Transactions $transactions,
        private ClockInterface $clock,
    ) {
    }

    public function handle(UpdatePricingGroupCommand $command): Result
    {
        $group = $this->groups->findById($command->groupId);
        if ($group === null) {
            return Result::err(DomainError::notFound('pricing_group.not_found', "Pricing group {$command->groupId} was not found."));
        }

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

        $slugOwner = $this->groups->findByClientAndSlug($group->clientId(), $slugValue);
        if ($slugOwner !== null && $slugOwner->id() !== $group->id()) {
            return Result::err(DomainError::conflict('pricing_group.slug_taken', "This client already has a pricing group '{$slugValue}'.", ['slug' => $slugValue]));
        }

        $priority = $group->isDefault() ? 0 : max(0, $command->priority);
        if (!$group->isDefault()) {
            foreach ($this->groups->forClient($group->clientId()) as $sibling) {
                if ($sibling->id() === $group->id() || $sibling->isDefault()) {
                    continue;
                }
                if ($sibling->priority() === $priority) {
                    return Result::err(DomainError::conflict('pricing_group.priority_taken', "Priority {$priority} is already used by group '{$sibling->slug()->value}'.", ['priority' => $priority, 'existing' => $sibling->slug()->value]));
                }
            }
        }

        $before = PricingAuditSnapshot::group($group);
        $now = $this->clock->now();
        $group->rename($name, $now);
        $group->changeSlug(PricingGroupSlug::of($slugValue), $now);
        $group->reprioritise($priority, $now);
        $group->setDeviceType($deviceType, $now);

        $groupId = $group->id();
        \assert($groupId !== null);
        $clientId = $group->clientId();

        $this->transactions->run(function () use ($group, $before, $clientId, $groupId, $command): void {
            $this->groups->save($group);

            $entry = $command->actorId !== null
                ? AuditEntry::forAdminUser($command->actorId, $clientId, 'pricing_group.updated')
                : AuditEntry::forSystem('pricing_group.updated', $clientId);

            $this->audit->record($entry->withTarget('pricing_group', $groupId)->withChange($before, PricingAuditSnapshot::group($group)));
        });

        return Result::ok(null);
    }

    private static function slugify(string $name): string
    {
        $slug = strtolower($name);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);

        return trim($slug, '-');
    }
}
