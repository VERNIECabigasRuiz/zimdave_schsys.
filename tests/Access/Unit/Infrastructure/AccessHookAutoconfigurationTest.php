<?php

declare(strict_types=1);

namespace App\Tests\Access\Unit\Infrastructure;

use App\Access\Domain\Model\User;
use App\Access\Domain\Service\Account\AccountReactivationGuardInterface;
use App\Access\Domain\Service\Account\AccountRoleChangeGuardInterface;
use App\Access\Infrastructure\AccessHookAutoconfiguration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\Compiler\ResolveInstanceofConditionalsPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class AccessHookAutoconfigurationTest extends TestCase
{
    public function testAnyAutoconfiguredRuleIsTagged(): void
    {
        // Initialize
        $container = new ContainerBuilder();
        AccessHookAutoconfiguration::register($container);
        $roleGuard = new class implements AccountRoleChangeGuardInterface {
            public function refusal(User $account, ?string $originalRole): ?string
            {
                return null;
            }
        };
        $reactivationGuard = new class implements AccountReactivationGuardInterface {
            public function assertCanReactivate(User $account): void
            {
            }
        };
        $container->setDefinition('role_guard', (new Definition($roleGuard::class))->setAutoconfigured(true));
        $container->setDefinition(
            'reactivation_guard',
            (new Definition($reactivationGuard::class))->setAutoconfigured(true),
        );

        // Execute
        (new ResolveInstanceofConditionalsPass())->process($container);

        // Assert
        $roleTagged = $container->getDefinition('role_guard')
            ->hasTag(AccessHookAutoconfiguration::ROLE_CHANGE_GUARD_TAG);
        $reactivationTagged = $container->getDefinition('reactivation_guard')
            ->hasTag(AccessHookAutoconfiguration::REACTIVATION_GUARD_TAG);
        self::assertTrue($roleTagged);
        self::assertTrue($reactivationTagged);
    }
}
