<?php

declare(strict_types=1);

namespace Gomrok\Tests\Support;

use Gomrok\Shared\Http\AdminAuthenticator;
use Gomrok\Shared\Http\AdminAuthResult;
use Gomrok\Shared\Http\AuthenticatedAdmin;
use Gomrok\Shared\Http\AuthRequestMeta;

/**
 * An {@see AdminAuthenticator} that returns a fixed outcome, for middleware
 * tests — mirrors {@see StubClientAuthenticator}. Records the last token and
 * meta it was called with.
 */
final class StubAdminAuthenticator implements AdminAuthenticator
{
    public ?string $lastToken = null;
    public ?AuthRequestMeta $lastMeta = null;

    public function __construct(private AdminAuthResult $result)
    {
    }

    public static function succeedingAs(AuthenticatedAdmin $admin): self
    {
        return new self(AdminAuthResult::success($admin));
    }

    public static function unauthorized(): self
    {
        return new self(AdminAuthResult::unauthorized());
    }

    public static function accountUnusable(): self
    {
        return new self(AdminAuthResult::accountUnusable());
    }

    public function authenticate(?string $token, AuthRequestMeta $meta): AdminAuthResult
    {
        $this->lastToken = $token;
        $this->lastMeta = $meta;

        return $this->result;
    }
}
