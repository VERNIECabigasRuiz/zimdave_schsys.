<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\EnrollmentRepository;
use App\Repository\LearnerRepository;
use App\Repository\SchoolYearRepository;
use App\Service\TimetableQuery;
use App\Sf1\Sf1Remarks;
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
        private readonly SchoolYearRepository $schoolYears,
        private readonly LearnerRepository $learners,
        private readonly EnrollmentRepository $enrollments,
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
