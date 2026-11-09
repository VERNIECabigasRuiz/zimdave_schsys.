<?php

declare(strict_types=1);

namespace App\Access\Infrastructure;

use App\Access\Domain\Service\Account\AccountReactivationGuardInterface;
use App\Access\Domain\Service\Account\AccountRoleChangeGuardInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Tags every module's account rules, wherever their services are defined, so the
 * account handlers ask all of them.
 */
final class AccessHookAutoconfiguration
{
    public const ROLE_CHANGE_GUARD_TAG = 'access.role_change_guard';
    public const REACTIVATION_GUARD_TAG = 'access.reactivation_guard';

    public static function register(ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(AccountRoleChangeGuardInterface::class)
            ->addTag(self::ROLE_CHANGE_GUARD_TAG);
        $container->registerForAutoconfiguration(AccountReactivationGuardInterface::class)
            ->addTag(self::REACTIVATION_GUARD_TAG);
    }
}
