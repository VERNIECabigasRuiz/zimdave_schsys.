<?php

namespace App\Controller;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\SectionRepository;
use App\Repository\SubjectRepository;
use App\Service\SubjectManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * On a plan without a principal (Individual) the teacher sets up the subjects of
 * the grade level of the section they advise.
 */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher/subjects')]
class TeacherSubjectController extends AbstractSubjectController
{
    public function __construct(
        SubjectRepository $subjects,
        SubjectManager $manager,
        SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly SectionRepository $sections,
    ) {
        parent::__construct($subjects, $manager, $schoolYears);
    }

    #[Route('', name: 'teacher_subjects', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        [$school, $schoolYear] = $this->ownSchool($user);
        if (null === $scope = $this->scope($user, $school, $schoolYear)) {
            return $this->render('teacher/subjects_need_section.html.twig', ['school' => $school, 'school_year' => $schoolYear]);
        }

        return $this->renderList($scope);
    }

    #[Route('/new', name: 'teacher_subjects_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        $scope = $this->requireScope($user);

        return $scope instanceof Response ? $scope : $this->handleForm($request, $scope, $this->newSubject($request, $scope));
    }

    #[Route('/{subject}/edit', name: 'teacher_subjects_edit', requirements: ['subject' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, #[CurrentUser] User $user, int $subject): Response
    {
        $scope = $this->requireScope($user);

        return $scope instanceof Response ? $scope : $this->handleForm($request, $scope, $this->requireSubject($scope, $subject));
    }

    #[Route('/{subject}/delete', name: 'teacher_subjects_delete', requirements: ['subject' => '\d+'], methods: ['POST'])]
    public function delete(Request $request, #[CurrentUser] User $user, int $subject): Response
    {
        $scope = $this->requireScope($user);
        if ($scope instanceof Response) {
            return $scope;
        }
        $this->assertCsrf($request, 'subject-'.$subject);

        return $this->handleDelete($scope, $this->requireSubject($scope, $subject));
    }

    #[Route('/deped', name: 'teacher_subjects_deped', methods: ['POST'])]
    public function deped(Request $request, #[CurrentUser] User $user): Response
    {
        $scope = $this->requireScope($user);
        if ($scope instanceof Response) {
            return $scope;
        }
        $this->assertCsrf($request, 'deped-subjects');

        return $this->handleDepEd($request, $scope);
    }

    #[Route('/bring-back', name: 'teacher_subjects_bring_back', methods: ['POST'])]
    public function bringBack(Request $request, #[CurrentUser] User $user): Response
    {
        $scope = $this->requireScope($user);
        if ($scope instanceof Response) {
            return $scope;
        }
        $this->assertCsrf($request, 'bring-back-subjects');

        return $this->handleBringBack($scope);
    }

    /**
     * The teacher's school this S.Y.; 403 when the plan has a principal (they set up subjects).
     *
     * @return array{0: School|null, 1: SchoolYear|null}
     */
    private function ownSchool(User $user): array
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $school = $schoolYear ? $this->assignments->findSchoolOf($user, $schoolYear, User::ROLE_TEACHER) : null;
        if (null !== $school && (null === $school->getPlan() || $school->getPlan()->includesPrincipal())) {
            throw $this->createAccessDeniedException('Your principal sets up the subjects of your school.');
        }

        return [$school, $schoolYear];
    }

    /** Null until the teacher advises a section this S.Y. */
    private function scope(User $user, ?School $school, ?SchoolYear $schoolYear): ?SubjectScope
    {
        $advisory = $school && $schoolYear ? $this->sections->findAdvisory($user, $schoolYear) : null;
        if (null === $advisory || $advisory->getSchool() !== $school) {
            return null;
        }

        return new SubjectScope(
            $school,
            $schoolYear,
            [$advisory->getGradeLevel()],
            'teacher_subjects',
            [],
            'teacher/_layout.html.twig',
            $advisory->getTrack(),
            $advisory->getStrand(),
        );
    }

    private function requireScope(User $user): SubjectScope|Response
    {
        [$school, $schoolYear] = $this->ownSchool($user);

        return $this->scope($user, $school, $schoolYear) ?? $this->redirectToRoute('teacher_subjects');
    }

    /** Checked after the plan check, so a teacher on a principal's plan always gets 403. */
    private function assertCsrf(Request $request, string $id): void
    {
        if (!$this->isCsrfTokenValid($id, $request->getPayload()->getString('_token'))) {
            throw new InvalidCsrfTokenException('Invalid CSRF token.');
        }
    }
}
