<?php

declare(strict_types=1);

namespace Gomrok\Modules\Providers\Application\Routing;

/**
 * Why the router dropped a candidate provider account. Recorded on the
 * {@see RoutingDecision} snapshot so a later "why did it pick X" question is
 * answerable without re-running the resolver.
 */
enum RejectionReason: string
{
    case GroupLinkDisabled = 'group_link_disabled';
    case AccountNotFound = 'account_not_found';
    case AccountDisabled = 'account_disabled';
    case ModeMismatch = 'mode_mismatch';
    case CountryNotServed = 'country_not_served';
    case PurchaseTypeUnsupportedByProvider = 'purchase_type_unsupported_by_provider';
    case MethodNotSupportedByAccount = 'method_not_supported_by_account';
}
