<?php

namespace App\Controller;

use App\Access\Domain\Model\User;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Learners\Domain\Model\Repository\LearnerRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Classes\Application\Service\Timetable\TimetableQuery;
use App\Learners\Domain\Service\Sf1\Sf1Remarks;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The student's home: where they are enrolled and their section's timetable, read only. */
#[IsGranted(User::ROLE_STUDENT)]
#[Route('/student')]
class StudentController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly LearnerRepositoryInterface $learners,
        private readonly EnrollmentRepositoryInterface $enrollments,
        private readonly TimetableQuery $timetable,
        private readonly Sf1Remarks $remarks,
    ) {
    }

    #[Route('', name: 'student_dashboard', methods: ['GET'])]
    public function dashboard(Request $request, #[CurrentUser] User $user): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $learner = $this->learners->findOneByUser($user);
        $enrollment = $schoolYear && $learner ? $this->enrollments->findCurrent($learner, $schoolYear) : null;

        $section = $enrollment?->getSection();
        $state = null;
        if (null !== $section) {
            $terms = $this->timetable->termsFor($section);
            $term = $request->query->getInt('term', $terms[0]);
            $state = $this->timetable->sectionState($section, \in_array($term, $terms, true) ? $term : $terms[0]);
        }

        return $this->render('student/dashboard.html.twig', [
            'school_year' => $schoolYear,
            'learner' => $learner,
            'enrollment' => $enrollment,
            'remarks_text' => null !== $enrollment ? $this->remarks->textFor($enrollment) : null,
            'section' => $section,
            'state' => $state,
        ]);
    }
}
