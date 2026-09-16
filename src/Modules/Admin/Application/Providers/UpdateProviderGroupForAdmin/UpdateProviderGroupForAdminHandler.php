<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Providers\UpdateProviderGroupForAdmin;

use Gomrok\Modules\Providers\Application\ChangeProviderGroupStatus\ChangeProviderGroupStatusHandler;
use Gomrok\Modules\Providers\Application\ConfigureProviderGroup\ConfigureProviderGroupCommand;
use Gomrok\Modules\Providers\Application\ConfigureProviderGroup\ConfigureProviderGroupHandler;
use Gomrok\Shared\Domain\Result;

final readonly class UpdateProviderGroupForAdminHandler
{
    public function __construct(
        private ConfigureProviderGroupHandler $configure,
        private ChangeProviderGroupStatusHandler $changeStatus,
    ) {
    }

    public function handle(UpdateProviderGroupForAdminCommand $command): Result
    {
        $configureResult = $this->configure->handle(new ConfigureProviderGroupCommand(
            groupId: $command->groupId,
            countries: $command->countries,
            purchaseTypes: $command->purchaseTypes,
            methods: $command->methods,
            name: $command->name,
            currencyCode: $command->currencyCode,
            clearCurrency: $command->currencyCode === null,
            actorId: $command->actorId,
        ));
        if ($configureResult->isErr()) {
            return $configureResult;
        }

        return $command->active
            ? $this->changeStatus->enable($command->groupId, $command->actorId)
            : $this->changeStatus->disable($command->groupId, $command->actorId);
    }
}
