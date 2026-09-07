<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    #[Test]
    public function autoloadingAndPhpunitWork(): void
    {
        self::assertTrue(class_exists(\Gomrok\Bootstrap\AppFactory::class));
        self::assertSame('8.4', substr(PHP_VERSION, 0, 3));
    }
}
