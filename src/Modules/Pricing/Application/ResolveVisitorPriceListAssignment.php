<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application;

use Gomrok\Modules\Pricing\Domain\PriceListAssignment;
use Gomrok\Modules\Pricing\Domain\PriceListAssignmentRepository;
use Gomrok\Modules\Pricing\Domain\PriceListRepository;
use Psr\Clock\ClockInterface;

/**
 * The deterministic visitor -> A/B price list bucket-assignment service
 * (Phase 15 Q4/Q5, decided fresh at Phase 24 Q6/Q7). Not a Command/Result
 * use case triggered directly from HTTP — this is a service `PriceResolver`
 * and `PriceCatalog` call internally when a caller supplies a `visitor_ref`.
 *
 * On first sight for a `(pricingGroupId, visitorRef)` pair, the bucket is
 * chosen by a deterministic hash over the group's currently-**enabled** lists
 * (`PriceListRepository::enabledForGroup()`, control first then by id — a
 * stable order) and persisted. Later visits read the stored row; if its list
 * has since been disabled, the row is reassigned to the group's control list
 * on that read (disable-fallback, matching {@see PriceListResolver}'s own
 * behaviour for an explicit `$priceListId`).
 *
 * Returns `null` — never throws — when the group has no price list at all
 * (not even a control row), mirroring how {@see PriceListResolver::apply()}
 * itself tolerates a `null` list id by leaving the base price untouched.
 *
 * Not audited: this fires on ordinary catalogue/price-resolution traffic
 * (potentially every visitor), not an admin write — the persisted row itself
 * is the durable history a report needs.
 */
final readonly class ResolveVisitorPriceListAssignment
{
    public function __construct(
        private PriceListAssignmentRepository $assignments,
        private PriceListRepository $lists,
        private ClockInterface $clock,
    ) {
    }

    public function forVisitor(int $clientId, int $pricingGroupId, string $visitorRef): ?int
    {
        $hash = self::hash($pricingGroupId, $visitorRef);
        $existing = $this->assignments->findByGroupAndHash($pricingGroupId, $hash);

        if ($existing !== null) {
            return $this->reconcile($existing);
        }

        $bucketListId = $this->bucket($pricingGroupId, $hash);
        if ($bucketListId === null) {
            return null;
        }

        $now = $this->clock->now();
        $assignment = PriceListAssignment::assign($clientId, $pricingGroupId, $hash, $bucketListId, $now);
        $authoritative = $this->assignments->insertOrGetExisting($assignment);

        // A concurrent request may have won the insert race with a different
        // (still-valid) bucket than the one we computed — always defer to
        // whatever actually got persisted.
        return $authoritative->priceListId();
    }

    private function reconcile(PriceListAssignment $assignment): int
    {
        $list = $this->lists->findById($assignment->priceListId());
        if ($list !== null && $list->isEnabled()) {
            return $assignment->priceListId();
        }

        $control = $this->lists->findControlForGroup($assignment->pricingGroupId());
        if ($control === null) {
            // Nothing better to fall back to — report the stored id as-is.
            return $assignment->priceListId();
        }
        $controlId = $control->id();
        \assert($controlId !== null);

        if ($controlId !== $assignment->priceListId()) {
            $assignmentId = $assignment->id();
            \assert($assignmentId !== null);
            $this->assignments->reassign($assignmentId, $controlId, $this->clock->now());
        }

        return $controlId;
    }

    private function bucket(int $pricingGroupId, string $visitorRefHash): ?int
    {
        $enabled = $this->lists->enabledForGroup($pricingGroupId);
        if ($enabled === []) {
            return $this->lists->findControlForGroup($pricingGroupId)?->id();
        }

        $index = hexdec(substr($visitorRefHash, 0, 8)) % \count($enabled);

        return $enabled[$index]->id();
    }

    private static function hash(int $pricingGroupId, string $visitorRef): string
    {
        return hash('sha256', $pricingGroupId . ':' . trim($visitorRef));
    }
}
