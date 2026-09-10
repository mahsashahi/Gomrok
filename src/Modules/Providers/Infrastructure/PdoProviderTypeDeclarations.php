<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Infrastructure;

use Gomrok\Modules\Providers\Domain\Capability;
use Gomrok\Modules\Providers\Domain\ProviderCapabilities;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclaration;
use Gomrok\Modules\Providers\Domain\ProviderTypeDeclarations;
use Gomrok\Modules\Providers\Domain\PurchaseType;
use Gomrok\Shared\Infrastructure\Persistence\Row;
use PDO;

/**
 * MySQL {@see ProviderTypeDeclarations} — joins `provider_types` with
 * `provider_type_capabilities` / `provider_type_purchase_types`.
 */
final readonly class PdoProviderTypeDeclarations implements ProviderTypeDeclarations
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findByCode(string $providerTypeCode): ?ProviderTypeDeclaration
    {
        $statement = $this->pdo->prepare('SELECT id, code FROM provider_types WHERE code = :code');
        $statement->execute(['code' => $providerTypeCode]);
        $row = $statement->fetch();

        if (!\is_array($row)) {
            return null;
        }

        return $this->build(Row::int($row['id'] ?? null), Row::str($row['code'] ?? ''));
    }

    public function all(): array
    {
        $statement = $this->pdo->query('SELECT id, code FROM provider_types ORDER BY code');
        if ($statement === false) {
            return [];
        }

        $declarations = [];
        while (($row = $statement->fetch()) !== false) {
            if (\is_array($row)) {
                $declarations[] = $this->build(Row::int($row['id'] ?? null), Row::str($row['code'] ?? ''));
            }
        }

        return $declarations;
    }

    private function build(int $providerTypeId, string $code): ProviderTypeDeclaration
    {
        return new ProviderTypeDeclaration(
            $code,
            $this->purchaseTypes($providerTypeId),
            ProviderCapabilities::of(...$this->capabilities($providerTypeId)),
        );
    }

    /**
     * @return list<Capability>
     */
    private function capabilities(int $providerTypeId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT pc.code
               FROM provider_type_capabilities ptc
               JOIN provider_capabilities pc ON pc.id = ptc.capability_id
              WHERE ptc.provider_type_id = :id',
        );
        $statement->execute(['id' => $providerTypeId]);

        $capabilities = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $capability = Capability::tryFrom(Row::str($value));
            if ($capability !== null) {
                $capabilities[] = $capability;
            }
        }

        return $capabilities;
    }

    /**
     * @return list<PurchaseType>
     */
    private function purchaseTypes(int $providerTypeId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT purchase_type FROM provider_type_purchase_types WHERE provider_type_id = :id',
        );
        $statement->execute(['id' => $providerTypeId]);

        $purchaseTypes = [];
        while (($value = $statement->fetchColumn()) !== false) {
            $purchaseType = PurchaseType::tryFrom(Row::str($value));
            if ($purchaseType !== null) {
                $purchaseTypes[] = $purchaseType;
            }
        }

        return $purchaseTypes;
    }
}
