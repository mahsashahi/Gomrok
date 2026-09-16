<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Vouchers\UpdateVoucherForAdmin;

use Gomrok\Modules\Vouchers\Application\ChangeVoucherStatus\ChangeVoucherStatusHandler;
use Gomrok\Modules\Vouchers\Application\UpdateVoucher\UpdateVoucherCommand;
use Gomrok\Modules\Vouchers\Application\UpdateVoucher\UpdateVoucherHandler;
use Gomrok\Shared\Domain\Result;

final readonly class UpdateVoucherForAdminHandler
{
    public function __construct(
        private UpdateVoucherHandler $updateVoucher,
        private ChangeVoucherStatusHandler $changeStatus,
    ) {
    }

    public function handle(UpdateVoucherForAdminCommand $command): Result
    {
        $updated = $this->updateVoucher->handle(new UpdateVoucherCommand(
            clientId: $command->clientId,
            voucherId: $command->voucherId,
            name: $command->name,
            description: $command->description,
            validFrom: $command->validFrom,
            validUntil: $command->validUntil,
            firstPurchaseOnly: $command->firstPurchaseOnly,
            minPurchaseMinor: $command->minPurchaseMinor,
            minPurchaseCurrency: $command->minPurchaseCurrency,
            defaultDiscountType: $command->defaultDiscountType,
            defaultPercentBp: $command->defaultPercentBp,
            actorId: $command->actorId,
        ));
        if ($updated->isErr()) {
            return $updated;
        }

        return $command->active
            ? $this->changeStatus->enable($command->voucherId, $command->clientId, $command->actorId)
            : $this->changeStatus->disable($command->voucherId, $command->clientId, $command->actorId);
    }
}
