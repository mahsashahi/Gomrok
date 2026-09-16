<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Providers\UpdateProviderAccountForAdmin;

use Gomrok\Modules\Providers\Application\ChangeProviderAccountStatus\ChangeProviderAccountStatusHandler;
use Gomrok\Modules\Providers\Application\SetProviderAccountMarkets\SetProviderAccountMarketsCommand;
use Gomrok\Modules\Providers\Application\SetProviderAccountMarkets\SetProviderAccountMarketsHandler;
use Gomrok\Shared\Domain\Result;

final readonly class UpdateProviderAccountForAdminHandler
{
    public function __construct(
        private SetProviderAccountMarketsHandler $setMarkets,
        private ChangeProviderAccountStatusHandler $changeStatus,
    ) {
    }

    public function handle(UpdateProviderAccountForAdminCommand $command): Result
    {
        $marketsResult = $this->setMarkets->handle(new SetProviderAccountMarketsCommand(
            accountId: $command->accountId,
            countries: $command->countries,
            methods: $command->methods,
            name: $command->name,
            actorId: $command->actorId,
        ));
        if ($marketsResult->isErr()) {
            return $marketsResult;
        }

        return $command->active
            ? $this->changeStatus->enable($command->accountId, $command->actorId)
            : $this->changeStatus->disable($command->accountId, null, $command->actorId);
    }
}
