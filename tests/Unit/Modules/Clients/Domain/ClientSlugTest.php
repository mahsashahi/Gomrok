<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Clients\Domain;

use Gomrok\Modules\Clients\Domain\ClientSlug;
use Gomrok\Modules\Clients\Domain\InvalidClientSlug;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ClientSlugTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function slugs(): iterable
    {
        yield 'simple' => ['televika', true];
        yield 'with digits' => ['client-42', true];
        yield 'single char' => ['a', true];
        yield 'internal hyphens' => ['a-b-c', true];
        yield 'uppercase' => ['Televika', false];
        yield 'leading hyphen' => ['-nope', false];
        yield 'trailing hyphen' => ['nope-', false];
        yield 'space' => ['no space', false];
        yield 'empty' => ['', false];
        yield 'underscore' => ['no_underscore', false];
    }

    #[Test]
    #[DataProvider('slugs')]
    public function validatesFormat(string $value, bool $valid): void
    {
        self::assertSame($valid, ClientSlug::isValid($value));

        if ($valid) {
            self::assertSame($value, ClientSlug::of($value)->value);
        } else {
            $this->expectException(InvalidClientSlug::class);
            ClientSlug::of($value);
        }
    }

    #[Test]
    public function errorCarriesTheOffendingValue(): void
    {
        $error = ClientSlug::error('Bad Slug');

        self::assertSame('client.invalid_slug', $error->code);
        self::assertSame('Bad Slug', $error->context['slug'] ?? null);
    }
}
