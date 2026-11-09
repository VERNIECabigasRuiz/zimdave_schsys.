<?php

declare(strict_types=1);

namespace App\Core\Presentation\Twig;

use App\Core\Domain\Service\Menu\RoleMenuInterface;
use Twig\Attribute\AsTwigFunction;

/** The menu questions the role layouts ask about the signed-in account. */
class RoleMenuExtension
{
    public function __construct(private readonly RoleMenuInterface $menu)
    {
    }

    #[AsTwigFunction('has_teaching_load')]
    public function hasTeachingLoad(int $accountId): bool
    {
        return $this->menu->hasTeachingLoad($accountId);
    }

    #[AsTwigFunction('has_id')]
    public function hasId(int $accountId): bool
    {
        return $this->menu->hasId($accountId);
    }

    #[AsTwigFunction('advises_section')]
    public function advisesSection(int $accountId): bool
    {
        return $this->menu->advisesSection($accountId);
    }
}
