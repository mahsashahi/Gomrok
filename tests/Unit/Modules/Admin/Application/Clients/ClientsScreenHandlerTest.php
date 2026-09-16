<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application\Clients;

use DateTimeImmutable;
use Gomrok\Modules\Admin\Application\Clients\ClientsScreenHandler;
use Gomrok\Modules\Clients\Application\ClientSnapshot;
use Gomrok\Modules\Clients\Domain\ApiKeyPrefix;
use Gomrok\Modules\Clients\Domain\ClientApiKey;
use Gomrok\Modules\Clients\Domain\ClientStatus;
use Gomrok\Tests\Support\InMemoryClientApiKeyRepository;
use Gomrok\Tests\Support\InMemoryClientDirectory;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientsScreenHandlerTest extends TestCase
{
    private InMemoryClientDirectory $clients;
    private InMemoryClientApiKeyRepository $apiKeys;
    private StubProviderAccountDirectory $providerAccounts;
    private ClientsScreenHandler $handler;

    protected function setUp(): void
    {
        $this->clients = new InMemoryClientDirectory();
        $this->apiKeys = new InMemoryClientApiKeyRepository();
        $this->providerAccounts = new StubProviderAccountDirectory();
        $this->handler = new ClientsScreenHandler($this->clients, $this->apiKeys, $this->providerAccounts);
    }

    private function snapshot(int $id, string $name, ClientStatus $status = ClientStatus::Active): ClientSnapshot
    {
        return new ClientSnapshot($id, strtolower($name), $name, $status, 'USD', null, 'UTC', '2026-09-01 10:00:00');
    }

    private function key(int $clientId, ApiKeyPrefix $prefix, bool $active = true): ClientApiKey
    {
        $now = new DateTimeImmutable('2026-09-16T12:00:00+00:00');
        $key = ClientApiKey::issue($clientId, bin2hex(random_bytes(4)), hash('sha256', 'secret'), $prefix, 'abcd', null, $now);
        if (!$active) {
            $key->revoke(null, $now);
        }

        return $key;
    }

    #[Test]
    public function listsEveryClientWithDerivedEnvironmentAndStats(): void
    {
        $this->clients->add($this->snapshot(1, 'Live Co'));
        $this->clients->add($this->snapshot(2, 'Test Co'));
        $this->clients->add($this->snapshot(3, 'Disabled Co', ClientStatus::Disabled));
        $this->apiKeys->save($this->key(1, ApiKeyPrefix::Live));
        $this->apiKeys->save($this->key(2, ApiKeyPrefix::Test));

        $result = $this->handler->build('all', null);

        self::assertSame(3, $result->stats->all);
        self::assertSame(1, $result->stats->live);
        self::assertSame(1, $result->stats->disabled);
        self::assertCount(3, $result->rows);

        $byName = [];
        foreach ($result->rows as $row) {
            $byName[$row->name] = $row;
        }
        self::assertSame('Live', $byName['Live Co']->environment);
        self::assertSame('Test', $byName['Test Co']->environment);
        self::assertSame('No active keys', $byName['Disabled Co']->environment);
    }

    #[Test]
    public function filtersToLiveOnly(): void
    {
        $this->clients->add($this->snapshot(1, 'Live Co'));
        $this->clients->add($this->snapshot(2, 'Test Co'));
        $this->apiKeys->save($this->key(1, ApiKeyPrefix::Live));
        $this->apiKeys->save($this->key(2, ApiKeyPrefix::Test));

        $result = $this->handler->build('live', null);

        self::assertCount(1, $result->rows);
        self::assertSame('Live Co', $result->rows[0]->name);
    }

    #[Test]
    public function filtersToDisabledOnly(): void
    {
        $this->clients->add($this->snapshot(1, 'Active Co'));
        $this->clients->add($this->snapshot(2, 'Disabled Co', ClientStatus::Disabled));

        $result = $this->handler->build('disabled', null);

        self::assertCount(1, $result->rows);
        self::assertSame('Disabled Co', $result->rows[0]->name);
    }

    #[Test]
    public function aRevokedKeyDoesNotCountTowardTheLiveEnvironment(): void
    {
        $this->clients->add($this->snapshot(1, 'Co'));
        $this->apiKeys->save($this->key(1, ApiKeyPrefix::Live, active: false));

        $result = $this->handler->build('all', null);

        self::assertSame('No active keys', $result->rows[0]->environment);
        self::assertSame(0, $result->stats->live);
    }

    #[Test]
    public function detailListsApiKeysNewestFirstAndMasksThem(): void
    {
        $this->clients->add($this->snapshot(1, 'Co'));
        $older = $this->key(1, ApiKeyPrefix::Test);
        $this->apiKeys->save($older);
        $newer = ClientApiKey::issue(1, 'newkey01', hash('sha256', 'x'), ApiKeyPrefix::Live, 'wxyz', 'mobile', new DateTimeImmutable('2026-09-17T12:00:00+00:00'));
        $this->apiKeys->save($newer);

        $detail = $this->handler->build('all', 1)->selected;

        self::assertNotNull($detail);
        self::assertCount(2, $detail->apiKeys);
        self::assertSame('mobile', $detail->apiKeys[0]->label);
        self::assertStringContainsString('••••', $detail->apiKeys[0]->displayToken);
        self::assertSame('gk_live_newkey01.••••••••wxyz', $detail->apiKeys[0]->displayToken);
    }

    #[Test]
    public function detailListsOnlyActiveEnabledProviders(): void
    {
        $this->clients->add($this->snapshot(1, 'Co'));
        $this->providerAccounts
            ->add(10, 1, 'stripe-test', 'stripe', status: 'active')
            ->add(11, 1, 'mollie-test', 'mollie', status: 'disabled');

        $detail = $this->handler->build('all', 1)->selected;

        self::assertNotNull($detail);
        self::assertSame(['Stripe-test'], $detail->enabledProviders);
    }

    #[Test]
    public function fallsBackToTheFirstFilteredClientWhenNoneIsSelected(): void
    {
        $this->clients->add($this->snapshot(1, 'Alpha'));
        $this->clients->add($this->snapshot(2, 'Beta'));

        $result = $this->handler->build('all', null);

        self::assertNotNull($result->selected);
        self::assertSame('Alpha', $result->selected->name);
    }

    #[Test]
    public function noClientsMeansNoSelectionAndZeroStats(): void
    {
        $result = $this->handler->build('all', null);

        self::assertSame(0, $result->stats->all);
        self::assertSame([], $result->rows);
        self::assertNull($result->selected);
    }
}
