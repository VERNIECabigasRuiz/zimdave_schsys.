<?php

namespace App\Controller;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\UserRepository;
use App\Security\Voter\SchoolVoter;
use App\Service\TimetableQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A teacher's teaching load and weekly timetable, read-only: for the principal and for the teacher. */
class TeacherLoadController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly UserRepository $users,
        private readonly TimetableQuery $query,
    ) {
    }

    #[Route('/schools/{id}/teachers/{teacher}/load', name: 'school_teacher_load', requirements: ['id' => '\d+', 'teacher' => '\d+'], methods: ['GET'])]
    #[IsGranted(SchoolVoter::MANAGE, 'school')]
    public function forPrincipal(Request $request, School $school, int $teacher): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $teacher = $this->users->find($teacher);
        if (null === $schoolYear || null === $teacher || $this->assignments->findSchoolOf($teacher, $schoolYear, User::ROLE_TEACHER) !== $school) {
            throw $this->createNotFoundException('This teacher is not in the school this school year.');
        }

        return $this->page($request, $teacher, $schoolYear, $school, $this->isGranted('ROLE_ADMIN') ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig');
    }

    #[Route('/teacher/load', name: 'teacher_load', methods: ['GET'])]
    #[IsGranted(User::ROLE_TEACHER)]
    public function mine(Request $request, #[CurrentUser] User $user): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $school = $schoolYear ? $this->assignments->findSchoolOf($user, $schoolYear, User::ROLE_TEACHER) : null;

        return $this->page($request, $user, $schoolYear, $school, 'teacher/_layout.html.twig');
    }

    private function page(Request $request, User $teacher, ?SchoolYear $schoolYear, ?School $school, string $layout): Response
    {
        $week = null === $school || null === $schoolYear ? null : $this->query->teacherWeek($teacher, $schoolYear, 0);
        $hasSemesters = null !== $week && [] !== array_filter($week['loads'], static fn (array $l) => null !== $l['semester']);
        $term = 0;
        if ($hasSemesters) {
            $term = $request->query->getInt('term', 1);
            $term = \in_array($term, [1, 2], true) ? $term : 1;
            $week = $this->query->teacherWeek($teacher, $schoolYear, $term);
        }

        return $this->render('teacher_load/show.html.twig', [
            'layout' => $layout,
            'teacher' => $teacher,
            'school' => $school,
            'school_year' => $schoolYear,
            'week' => $week,
            'term' => $term,
            'has_semesters' => $hasSemesters,
            'self' => $teacher === $this->getUser(),
        ]);
    }
}
