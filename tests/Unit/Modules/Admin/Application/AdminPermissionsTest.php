<?php

declare(strict_types=1);

namespace Gomrok\Tests\Unit\Modules\Admin\Application;

use Gomrok\Modules\Admin\Application\AdminPermission;
use Gomrok\Modules\Admin\Application\AdminPermissions;
use Gomrok\Modules\Admin\Domain\AdminRole;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AdminPermissionsTest extends TestCase
{
    #[Test]
    public function adminHoldsEveryPermission(): void
    {
        self::assertSame(AdminPermission::cases(), AdminPermissions::for(AdminRole::Admin));
        self::assertTrue(AdminPermissions::roleHas(AdminRole::Admin, AdminPermission::ProviderConfigsUpdate));
        self::assertTrue(AdminPermissions::roleHas(AdminRole::Admin, AdminPermission::AdminUsersCreate));
    }

    #[Test]
    public function supportAgentHoldsOnlyViewPermissions(): void
    {
        $permissions = AdminPermissions::for(AdminRole::SupportAgent);

        self::assertNotEmpty($permissions);
        foreach ($permissions as $permission) {
            self::assertStringEndsWith('.view', $permission->value);
        }
    }

    #[Test]
    public function supportAgentCannotModifyProviderCredentialsPricingOrVoucherRules(): void
    {
        self::assertFalse(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::ProviderConfigsUpdate));
        self::assertFalse(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::ProviderConfigsCreate));
        self::assertFalse(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::PricingUpdate));
        self::assertFalse(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::VouchersUpdate));
        self::assertFalse(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::AdminUsersCreate));
    }

    #[Test]
    public function supportAgentCanStillViewEverythingItsRoleDescriptionNames(): void
    {
        self::assertTrue(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::ClientsView));
        self::assertTrue(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::AdminUsersView));
        self::assertTrue(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::PackagesView));
        self::assertTrue(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::PricingView));
        self::assertTrue(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::VouchersView));
        self::assertTrue(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::PaymentsView));
        self::assertTrue(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::SubscriptionsView));
        self::assertTrue(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::WebhooksView));
        self::assertTrue(AdminPermissions::roleHas(AdminRole::SupportAgent, AdminPermission::NotificationsView));
    }
}
