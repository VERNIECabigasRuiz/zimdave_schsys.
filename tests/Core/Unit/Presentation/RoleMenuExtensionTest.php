<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Presentation;

use App\Core\Domain\Service\Menu\RoleMenuInterface;
use App\Core\Presentation\Twig\RoleMenuExtension;
use PHPUnit\Framework\TestCase;

final class RoleMenuExtensionTest extends TestCase
{
    public function testAsksTheMenuHookForTheAccount(): void
    {
        // Initialize
        $menu = self::createStub(RoleMenuInterface::class);
        $menu->method('hasTeachingLoad')->willReturnMap([[7, false], [8, true]]);
        $menu->method('hasId')->willReturnMap([[7, true], [8, false]]);
        $menu->method('advisesSection')->willReturnMap([[7, true], [8, false]]);
        $extension = new RoleMenuExtension($menu);

        // Execute
        $answers = [
            [$extension->hasTeachingLoad(7), $extension->hasId(7), $extension->advisesSection(7)],
            [$extension->hasTeachingLoad(8), $extension->hasId(8), $extension->advisesSection(8)],
        ];

        // Assert
        self::assertSame([[false, true, true], [true, false, false]], $answers);
    }
}
