<?php

declare(strict_types=1);

namespace App\Staff\Infrastructure\LessonPlans;

use App\Staff\Domain\Model\Repository\ServiceRecordEntryRepositoryInterface;
use App\Access\Domain\Model\User;
use App\LessonPlans\Domain\Service\Signatory\SignatoryPositionsInterface;

/** A lesson plan's signatory signs with the designation on their service record's present row. */
final class LessonPlanSignatoryPositions implements SignatoryPositionsInterface
{
    public function __construct(private readonly ServiceRecordEntryRepositoryInterface $entries)
    {
    }

    public function positionOf(User $user): ?string
    {
        return $this->entries->findCurrent($user)?->getDesignation();
    }
}
