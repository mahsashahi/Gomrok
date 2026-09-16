<?php

declare(strict_types=1);

namespace Gomrok\Modules\Admin\Application\Packaging;

final readonly class GroupsTabResult
{
    /**
     * @param list<GroupListItem> $groups
     */
    public function __construct(
        public array $groups,
        public ?GroupDetail $selected,
    ) {
    }
}
