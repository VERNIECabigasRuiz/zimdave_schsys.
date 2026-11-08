<?php

namespace App\Controller;

use App\Schools\Domain\Service\Calendar\SchoolCalendar;
use App\Schools\Domain\Model\School;
use App\Classes\Domain\Model\Section;
use App\Access\Domain\Model\User;
use App\Repository\RollCallRepository;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Learners\Domain\Model\Repository\TransferRequestRepositoryInterface;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Classes\Application\Service\SectionManager;
use App\Classes\Application\Service\Timetable\TimetableQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The teacher's home. Class and grade pages come later. */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher')]
class TeacherController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly SectionRepositoryInterface $sections,
        private readonly SectionManager $sectionManager,
        private readonly TimetableQuery $timetable,
        private readonly SchoolClockInterface $clock,
        private readonly TransferRequestRepositoryInterface $transferRequests,
        private readonly SchoolCalendar $calendar,
        private readonly RollCallRepository $rollCalls,
    ) {
    }

    #[Route('', name: 'teacher_dashboard', methods: ['GET'])]
    public function dashboard(#[CurrentUser] User $user): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $school = $schoolYear ? $this->assignments->findSchoolOf($user, $schoolYear, User::ROLE_TEACHER) : null;
        $advisory = $schoolYear ? $this->sections->findAdvisory($user, $schoolYear) : null;

        return $this->render('teacher/dashboard.html.twig', [
            'school_year' => $schoolYear,
            'school' => $school,
            'advisory' => $advisory,
            'can_create_section' => null !== $school && null === $advisory && $this->ownsSections($school)
                && null === $this->sectionManager->refusal($school),
            'can_manage_subjects' => null !== $school && $this->ownsSections($school),
            // a school without a principal has no timetables
            'show_now' => null !== $school && !$this->ownsSections($school),
            // on a plan without a principal the adviser decides the requests leaving their section
            'transfers_waiting' => null !== $advisory && $this->ownsSections($advisory->getSchool())
                ? $this->transferRequests->countPendingOutgoing($advisory->getSchool(), $schoolYear, $advisory) : 0,
            'attendance_today' => $this->attendanceToday($advisory),
        ]);
    }

    /** @return array{schoolDay: bool, taken: bool} whether the advisory has classes today, and its roll call is in */
    private function attendanceToday(?Section $advisory): array
    {
        $today = $this->clock->now()->setTime(0, 0);
        if (null === $advisory || !$this->calendar->isSchoolDay($advisory->getSchool(), $advisory->getSchoolYear(), $today)) {
            return ['schoolDay' => false, 'taken' => false];
        }

        return ['schoolDay' => true, 'taken' => null !== $this->rollCalls->findOne($advisory, $today)];
    }

    /** The "Your class now" card: only the turbo-frame, so the dashboard can reload it in place. */
    #[Route('/now', name: 'teacher_now', methods: ['GET'])]
    public function now(#[CurrentUser] User $user): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();

        return $this->render('teacher/_now.html.twig', [
            'now' => $schoolYear ? $this->timetable->nowForTeacher($user, $schoolYear, $this->clock->now()) : null,
        ]);
    }

    /** Teachers manage their own section only when the plan has no principal. */
    private function ownsSections(School $school): bool
    {
        return null !== $school->getPlan() && !$school->getPlan()->includesPrincipal();
    }
}
