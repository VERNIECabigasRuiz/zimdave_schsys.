<?php

namespace App\Twig;

use App\Entity\User;
use App\Repository\ActivityLogRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

class AppExtension
{
    public function __construct(
        private readonly ActivityLogRepository $activityLogs,
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
    ) {
    }

    /**
     * "8:02 AM" for today, "Yesterday", otherwise "Oct 14, 2026".
     */
    #[AsTwigFilter('activity_time')]
    public function activityTime(\DateTimeInterface $time): string
    {
        $time = \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $today = new \DateTimeImmutable('today');

        return match (true) {
            $time >= $today => $time->format('g:i A'),
            $time >= $today->modify('-1 day') => 'Yesterday',
            default => $time->format('M j, Y'),
        };
    }

    /**
     * Latest entries for the "!" notifications menu in the admin top bar.
     *
     * @return list<\App\Entity\ActivityLog>
     */
    #[AsTwigFunction('latest_activity')]
    public function latestActivity(int $limit = 6): array
    {
        return $this->activityLogs->findRecent($limit);
    }

    /**
     * False for a teacher whose school this S.Y. has no principal (Individual plan):
     * such a school has no timetables, so the teacher has no teaching load pages.
     */
    #[AsTwigFunction('has_teaching_load')]
    public function hasTeachingLoad(User $teacher): bool
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $school = null === $schoolYear ? null : $this->assignments->findSchoolOf($teacher, $schoolYear, User::ROLE_TEACHER);

        return false !== $school?->getPlan()?->includesPrincipal();
    }
}
