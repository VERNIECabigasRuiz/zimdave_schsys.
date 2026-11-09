<?php

declare(strict_types=1);

namespace App\Tests\Classes\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Application\Service\Timetable\TimetableManager;
use App\Classes\Domain\Model\ClassPeriod;
use App\Classes\Domain\Model\ScheduleEntry;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Classes\Domain\Model\Weekday;
use Doctrine\ORM\EntityManagerInterface;

/** Calls the timetable manager as a use case would, saving after each call. */
final class SavingTimetableManager
{
    public function __construct(
        private readonly TimetableManager $manager,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function setTeacher(Section $section, Subject $subject, ?User $teacher): ?TeachingLoad
    {
        $load = $this->manager->setTeacher($section, $subject, $teacher);
        $this->em->flush();

        return $load;
    }

    public function plot(Section $section, Subject $subject, Weekday $day, ClassPeriod $period): ScheduleEntry
    {
        $entry = $this->manager->plot($section, $subject, $day, $period);
        $this->em->flush();

        return $entry;
    }

    public function remove(ScheduleEntry $entry): void
    {
        $this->manager->remove($entry);
        $this->em->flush();
    }
}
