<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure;

use Gomrok\Modules\Providers\Application\ProviderCatalog;
use Gomrok\Modules\Providers\Application\ProviderTypeSummary;
use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclaration;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclarations;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * MySQL {@see ProviderCatalog} — `provider_types` metadata enriched with each
 * type's declared purchase types and capabilities.
 */
final readonly class PdoProviderCatalog implements ProviderCatalog
{
    private const COLUMNS = 'id, code, name, requires_registration, api_capable';

    public function __construct(
        private PDO $pdo,
        private ProviderTypeDeclarations $declarations,
    ) {
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT ' . self::COLUMNS . ' FROM provider_types ORDER BY code');
        if ($statement === false) {
            return [];
        }

        $summaries = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $summaries[] = $this->toSummary($row);
            }
        }

        return $summaries;
    }

    public function find(string $code): ?ProviderTypeSummary
    {
        $statement = $this->pdo->prepare('SELECT ' . self::COLUMNS . ' FROM provider_types WHERE code = :code');
        $statement->execute(['code' => $code]);
        $row = $statement->fetch();

        return \is_array($row) ? $this->toSummary($row) : null;
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function toSummary(array $row): ProviderTypeSummary
    {
        $code = Row::str($row['code'] ?? '');
        $declaration = $this->declarations->findByCode($code);

        return new ProviderTypeSummary(
            Row::int($row['id'] ?? null),
            $code,
            Row::str($row['name'] ?? ''),
            Row::bool($row['requires_registration'] ?? null),
            Row::bool($row['api_capable'] ?? null),
            $declaration !== null ? $this->purchaseTypeValues($declaration) : [],
            $declaration !== null ? $this->capabilityValues($declaration) : [],
        );
    }

    /**
     * @return list<string>
     */
    private function purchaseTypeValues(ProviderTypeDeclaration $declaration): array
    {
        return array_map(static fn (PurchaseType $pt): string => $pt->value, $declaration->purchaseTypes());
    }

    /**
     * @return list<string>
     */
    private function capabilityValues(ProviderTypeDeclaration $declaration): array
    {
        return array_map(static fn (Capability $c): string => $c->value, $declaration->capabilities->all());
    }
}
