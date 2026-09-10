<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Packages\Application;

use Gomrok\Modules\Packages\Application\ChangePackageStatus\ChangePackageStatusHandler;
use Gomrok\Modules\Packages\Application\CreatePackage\CreatePackageCommand;
use Gomrok\Modules\Packages\Application\CreatePackage\CreatePackageHandler;
use Gomrok\Modules\Packages\Application\CreatePackage\CreatePackageResult;
use Gomrok\Modules\Packages\Application\SetPackageAvailability\SetPackageAvailabilityCommand;
use Gomrok\Modules\Packages\Application\SetPackageAvailability\SetPackageAvailabilityHandler;
use Gomrok\Modules\Packages\Application\UpdatePackage\UpdatePackageCommand;
use Gomrok\Modules\Packages\Application\UpdatePackage\UpdatePackageHandler;
use Gomrok\Shared\Domain\ErrorType;
use Gomrok\Tests\Support\FrozenClock;
use Gomrok\Tests\Support\InMemoryPackageRepository;
use Gomrok\Tests\Support\InMemoryReferenceCatalog;
use Gomrok\Tests\Support\RecordingAuditLogWriter;
use Gomrok\Tests\Support\StubClientDirectory;
use Gomrok\Tests\Support\StubProviderAccountDirectory;
use Gomrok\Tests\Support\SynchronousTransactions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PackageHandlersTest extends TestCase
{
    private const CLIENT = 7;

    private InMemoryPackageRepository $packages;
    private RecordingAuditLogWriter $audit;
    private FrozenClock $clock;

    protected function setUp(): void
    {
        $this->packages = new InMemoryPackageRepository();
        $this->audit = new RecordingAuditLogWriter();
        $this->clock = new FrozenClock('2026-09-10T12:00:00+00:00');
    }

    #[Test]
    public function createValidatesCodeClientAndUniqueness(): void
    {
        $bad = $this->createHandler()->handle(new CreatePackageCommand(self::CLIENT, 'Bad Code', 'x'));
        self::assertSame('package.invalid_code', $bad->error()->code);

        $missing = $this->createHandler()->handle(new CreatePackageCommand(999, 'starter', 'Starter'));
        self::assertSame(ErrorType::NotFound, $missing->error()->type);

        $ok = $this->createHandler()->handle(new CreatePackageCommand(self::CLIENT, 'starter', 'Starter'));
        self::assertTrue($ok->isOk());
        self::assertContains('package.created', $this->audit->actions());

        $dup = $this->createHandler()->handle(new CreatePackageCommand(self::CLIENT, 'starter', 'Starter II'));
        self::assertSame('package.code_taken', $dup->error()->code);
    }

    #[Test]
    public function theSameCodeIsAllowedForADifferentClient(): void
    {
        $handlerA = $this->createHandler(new StubClientDirectory(self::CLIENT, 'client-a'));
        $handlerB = $this->createHandler(new StubClientDirectory(8, 'client-b'));

        self::assertTrue($handlerA->handle(new CreatePackageCommand(self::CLIENT, 'starter', 'A'))->isOk());
        self::assertTrue($handlerB->handle(new CreatePackageCommand(8, 'starter', 'B'))->isOk());
    }

    #[Test]
    public function setAvailabilityValidatesEveryValueAndAccountOwnership(): void
    {
        $packageId = $this->create('pro');
        $accounts = (new StubProviderAccountDirectory())->add(5, self::CLIENT, 'stripe', 'stripe');
        $handler = new SetPackageAvailabilityHandler(
            $this->packages,
            new InMemoryReferenceCatalog(),
            $accounts,
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );

        self::assertSame('package.unknown_country', $handler->handle(new SetPackageAvailabilityCommand($packageId, countries: ['ZZ']))->error()->code);
        self::assertSame('package.unknown_currency', $handler->handle(new SetPackageAvailabilityCommand($packageId, currencies: ['ZZZ']))->error()->code);
        self::assertSame('package.unknown_method', $handler->handle(new SetPackageAvailabilityCommand($packageId, methods: ['crypto']))->error()->code);
        self::assertSame('package.account_not_owned', $handler->handle(new SetPackageAvailabilityCommand($packageId, providerAccountIds: [999]))->error()->code);

        $ok = $handler->handle(new SetPackageAvailabilityCommand($packageId, countries: ['de'], currencies: ['EUR'], methods: ['card'], providerAccountIds: [5]));
        self::assertTrue($ok->isOk());

        $stored = $this->packages->findById($packageId);
        self::assertNotNull($stored);
        self::assertSame(['DE'], $stored->countryCodes());
        self::assertSame([5], $stored->providerAccountIds());
        self::assertContains('package.availability_updated', $this->audit->actions());
    }

    #[Test]
    public function updateRejectsABlankNameButLeavesOmittedFields(): void
    {
        $packageId = $this->create('pro');
        $handler = new UpdatePackageHandler($this->packages, $this->audit, new SynchronousTransactions(), $this->clock);

        self::assertSame('package.name_required', $handler->handle(new UpdatePackageCommand($packageId, name: '  '))->error()->code);
        self::assertTrue($handler->handle(new UpdatePackageCommand($packageId, description: 'a plan'))->isOk());

        $stored = $this->packages->findById($packageId);
        self::assertNotNull($stored);
        self::assertSame('Pro', $stored->name());
        self::assertSame('a plan', $stored->description());
    }

    #[Test]
    public function changeStatusIsIdempotentAndHidesThePackage(): void
    {
        $packageId = $this->create('pro');
        $handler = new ChangePackageStatusHandler($this->packages, $this->audit, new SynchronousTransactions(), $this->clock);

        self::assertTrue($handler->disable($packageId)->isOk());
        self::assertTrue($handler->disable($packageId)->isOk());

        $stored = $this->packages->findById($packageId);
        self::assertNotNull($stored);
        self::assertFalse($stored->isActive());
    }

    private function create(string $code): int
    {
        $result = $this->createHandler()->handle(new CreatePackageCommand(self::CLIENT, $code, ucfirst($code)));
        $payload = $result->value();
        self::assertInstanceOf(CreatePackageResult::class, $payload);

        return $payload->packageId;
    }

    private function createHandler(?StubClientDirectory $clients = null): CreatePackageHandler
    {
        return new CreatePackageHandler(
            $this->packages,
            $clients ?? new StubClientDirectory(self::CLIENT),
            $this->audit,
            new SynchronousTransactions(),
            $this->clock,
        );
    }
}
