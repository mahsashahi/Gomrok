<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Shared\Domain;

use Gomrok\Shared\Domain\DomainError;
use Gomrok\Shared\Domain\ErrorType;
use Gomrok\Shared\Domain\Result;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResultTest extends TestCase
{
    #[Test]
    public function okCarriesTheValue(): void
    {
        $result = Result::ok(42);

        self::assertTrue($result->isOk());
        self::assertFalse($result->isErr());
        self::assertSame(42, $result->value());
    }

    #[Test]
    public function errCarriesTheError(): void
    {
        $error = DomainError::notFound('package.not_found', 'No such package');
        $result = Result::err($error);

        self::assertTrue($result->isErr());
        self::assertFalse($result->isOk());
        self::assertSame($error, $result->error());
        self::assertSame(ErrorType::NotFound, $result->error()->type);
    }

    #[Test]
    public function valueOnErrorThrows(): void
    {
        $this->expectException(LogicException::class);
        Result::err(DomainError::validation('x', 'x'))->value();
    }

    #[Test]
    public function errorOnOkThrows(): void
    {
        $this->expectException(LogicException::class);
        Result::ok('ok')->error();
    }

}
