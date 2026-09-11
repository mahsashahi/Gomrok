<?php

declare(strict_types=1);

namespace Gomrok\Modules\Vouchers\Domain;

enum VoucherStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
}
