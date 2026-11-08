<?php

declare(strict_types=1);

namespace App\Staff\Application\Service;

use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Staff\Domain\Model\PersonalDataSheet;
use App\Staff\Domain\Model\Repository\PersonalDataSheetRepositoryInterface;

/** What every profile tab does on save: write the tab onto the person's sheet, then date it and name the account. */
class PdsSaver
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly PersonalDataSheetRepositoryInterface $sheets,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param callable(PersonalDataSheet): void $change the tab's fields and its follow-up */
    public function save(int $actorId, callable $change): void
    {
        $actor = $this->scope->account($actorId);
        $sheet = $this->sheets->findFor($actor);
        if (null === $sheet) {
            $sheet = new PersonalDataSheet($actor);
            $this->sheets->add($sheet);
        }
        $change($sheet);
        $sheet->touch();
        if (null !== $name = $sheet->displayName()) {
            $actor->setFullName(mb_substr($name, 0, 120));
        }
        $this->activityLogger->log('Updated own profile');
    }
}
