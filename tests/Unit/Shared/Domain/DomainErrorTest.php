<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Domain;

use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\ErrorType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DomainErrorTest extends TestCase
{
    #[Test]
    public function factoriesSetTypeCodeMessageContext(): void
    {
        $error = DomainError::validation('voucher.expired', 'This voucher has expired', ['code' => 'SUMMER']);

        self::assertSame(ErrorType::Validation, $error->type);
        self::assertSame('voucher.expired', $error->code);
        self::assertSame('This voucher has expired', $error->message);
        self::assertSame(['code' => 'SUMMER'], $error->context);
    }

    #[Test]
    public function httpStatusFollowsTheType(): void
    {
        self::assertSame(422, DomainError::validation('a', 'a')->httpStatus());
        self::assertSame(404, DomainError::notFound('a', 'a')->httpStatus());
        self::assertSame(409, DomainError::conflict('a', 'a')->httpStatus());
        self::assertSame(403, DomainError::forbidden('a', 'a')->httpStatus());
        self::assertSame(401, DomainError::unauthorized('a', 'a')->httpStatus());
        self::assertSame(422, DomainError::unsupported('a', 'a')->httpStatus());
        self::assertSame(422, DomainError::ruleViolation('a', 'a')->httpStatus());
    }
}
