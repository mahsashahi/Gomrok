<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Domain;

/**
 * The client surface a payment originates from. A {@see ProviderGroup} may be
 * scoped to one device type (e.g. an iOS-only group that avoids a provider whose
 * fees break App Store rules); a group with no device type applies to any.
 */
enum DeviceType: string
{
    case Web = 'web';
    case Ios = 'ios';
    case Android = 'android';
}
