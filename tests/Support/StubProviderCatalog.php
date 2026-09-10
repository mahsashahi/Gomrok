<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Modules\Providers\Application\ProviderCatalog;
use Gomrok\Modules\Providers\Application\ProviderTypeSummary;

/**
 * The four known provider types with deterministic ids, for use-case tests.
 */
final class StubProviderCatalog implements ProviderCatalog
{
    /** @var array<string, ProviderTypeSummary> */
    private array $byCode = [];

    public function __construct()
    {
        $ids = ['stripe' => 1, 'mollie' => 2, 'paypal' => 3, 'ziraat' => 4];
        foreach ($ids as $code => $id) {
            $this->byCode[$code] = new ProviderTypeSummary($id, $code, ucfirst($code), $code !== 'ziraat', $code !== 'ziraat', [], []);
        }
    }

    public function all(): array
    {
        return array_values($this->byCode);
    }

    public function find(string $code): ?ProviderTypeSummary
    {
        return $this->byCode[$code] ?? null;
    }
}
