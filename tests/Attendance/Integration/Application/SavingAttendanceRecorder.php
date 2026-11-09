<?php

declare(strict_types=1);

namespace App\Tests\Attendance\Integration\Application;

use App\Access\Domain\Model\User;
use App\Attendance\Application\Service\AttendanceRecorder;
use App\Attendance\Domain\Model\LearnerMark;
use App\Attendance\Domain\Model\RollCall;
use App\Attendance\Domain\Model\Sf2Submission;
use App\Classes\Domain\Model\Section;
use Doctrine\ORM\EntityManagerInterface;

/** Calls the attendance recorder as a use case would, saving after each call that succeeds. */
final class SavingAttendanceRecorder
{
    public function __construct(
        private readonly AttendanceRecorder $recorder,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** @param array<int, ?LearnerMark> $marks */
    public function takeRollCall(Section $section, \DateTimeImmutable $date, array $marks, User $by): RollCall
    {
        $rollCall = $this->recorder->takeRollCall($section, $date, $marks, $by);
        $this->em->flush();

        return $rollCall;
    }

    public function submit(Section $section, \DateTimeImmutable $month, User $by, bool $confirmMissing): Sf2Submission
    {
        $submission = $this->recorder->submit($section, $month, $by, $confirmMissing);
        $this->em->flush();

        return $submission;
    }

    public function reopen(Section $section, \DateTimeImmutable $month): void
    {
        $this->recorder->reopen($section, $month);
        $this->em->flush();
    }
}
