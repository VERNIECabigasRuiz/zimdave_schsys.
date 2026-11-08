<?php

declare(strict_types=1);

namespace App\ServiceRecord;

use App\Access\Domain\Model\User;
use App\LessonPlans\Domain\Service\Signatory\SignatoryPositionsInterface;
use App\Repository\ServiceRecordEntryRepository;

/** A lesson plan's signatory signs with the designation on their service record's present row. */
final class LessonPlanSignatoryPositions implements SignatoryPositionsInterface
{
    public function __construct(private readonly ServiceRecordEntryRepository $entries)
    {
    }

    public function positionOf(User $user): ?string
    {
        return $this->entries->findCurrent($user)?->getDesignation();
    }
}
