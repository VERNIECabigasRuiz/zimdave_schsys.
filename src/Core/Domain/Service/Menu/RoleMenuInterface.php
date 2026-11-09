<?php

declare(strict_types=1);

namespace App\Core\Domain\Service\Menu;

/**
 * What the role layouts' menus show for the signed-in account. The module that reads every
 * other module implements it, since Core uses none of them.
 */
interface RoleMenuInterface
{
    /**
     * False for a teacher whose school this school year has no principal (Individual plan):
     * such a school has no timetables, so the teacher has no teaching load pages.
     */
    public function hasTeachingLoad(int $accountId): bool;

    /** Whether the account has a school this school year as teacher or principal, so it can print an ID. */
    public function hasId(int $accountId): bool;

    /** Whether the teacher advises a section this school year (they then have a "My class" page). */
    public function advisesSection(int $accountId): bool;
}
