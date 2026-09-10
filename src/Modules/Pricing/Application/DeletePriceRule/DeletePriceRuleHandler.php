<?php

declare(strict_types=1);

namespace Gomrok\Modules\Pricing\Application\DeletePriceRule;

use Gomrok\Modules\Pricing\Application\PriceRuleAuditSnapshot;
use Gomrok\Modules\Pricing\Domain\PriceRuleRepository;
use Gomrok\Shared\Application\Audit\AuditEntry;
use Gomrok\Shared\Application\Audit\AuditLogWriter;
use Gomrok\Shared\Application\Transactions;
use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\Result;

/**
 * Deletes a price rule by id. Scoped to the given client.
 */
final readonly class DeletePriceRuleHandler
{
    public function __construct(
        private PriceRuleRepository $rules,
        private AuditLogWriter $audit,
        private Transactions $transactions,
    ) {
    }

    public function handle(int $ruleId, int $clientId, ?int $actorId = null): Result
    {
        $rule = $this->rules->findById($ruleId);
        if ($rule === null || $rule->clientId() !== $clientId) {
            return Result::err(DomainError::notFound('price_rule.not_found', "Price rule {$ruleId} was not found for this client."));
        }

        $before = PriceRuleAuditSnapshot::of($rule);
        $packageId = $rule->packageId();

        $this->transactions->run(function () use ($ruleId, $clientId, $actorId, $before, $packageId): void {
            $this->rules->delete($ruleId);

            $entry = $actorId !== null
                ? AuditEntry::forAdminUser($actorId, $clientId, 'price_rule.deleted')
                : AuditEntry::forSystem('price_rule.deleted', $clientId);

            $this->audit->record(
                $entry
                    ->withTarget('price_rule', $ruleId)
                    ->withChange($before, null)
                    ->withContext(['package_id' => $packageId]),
            );
        });

        return Result::ok(null);
    }
}
