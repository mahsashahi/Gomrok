<?php

declare(strict_types=1);

namespace Gomrok\Modules\Checkout\Application\ConfirmCheckoutReturn;

final readonly class ConfirmCheckoutReturnCommand
{
    public function __construct(
        public string $returnToken,
    ) {
    }
}
