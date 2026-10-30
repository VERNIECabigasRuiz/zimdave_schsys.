<?php

namespace App\Controller;

use App\Entity\GradingPeriod;
use App\Entity\User;
use App\Grading\SectionGradeGrid;
use App\Security\Voter\GradeVoter;
use App\Security\Voter\LearnerVoter;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The adviser's class: the learners of the section they advise this S.Y. The advisory is
 * looked up on every request, so a teacher who stops being the adviser is refused at once.
 * An adviser can neither move a learner nor change an LRN.
 */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher/class', requirements: ['learner' => '\d+'])]
class TeacherClassController extends AbstractLearnerController
{
    #[Route('', name: 'teacher_class', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $scope = $this->scope($user);

        return $this->renderClassList($scope, $scope->fixedSection);
    }

    #[Route('/sf1', name: 'teacher_class_sf1', methods: ['GET'])]
    public function sf1(#[CurrentUser] User $user): Response
    {
        return $this->downloadSf1($this->scope($user)->fixedSection);
    }

    #[Route('/grades', name: 'teacher_class_grades', methods: ['GET'])]
    public function grades(#[CurrentUser] User $user, SectionGradeGrid $grid, #[MapQueryParameter] ?int $period = null): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $section = null === $schoolYear ? null : $this->sections->findAdvisory($user, $schoolYear);
        if (null === $section) {
            throw $this->createAccessDeniedException('You do not advise a section this school year.');
        }
        $this->denyAccessUnlessGranted(GradeVoter::VIEW_SECTION, $section);

        $periods = $schoolYear->getGradingPeriods();
        if (null === $period) {
            $selected = $grid->defaultPeriod($schoolYear);
        } else {
            $selected = $periods->findFirst(static fn (int $i, GradingPeriod $p) => $p->getId() === $period);
            if (null === $selected) {
                throw $this->createNotFoundException('No such grading period.');
            }
        }

        return $this->render('grades/class.html.twig', [
            'section' => $section,
            'periods' => $periods,
            'period' => $selected,
            'grid' => null === $selected ? null : $grid->build($section, $selected),
        ]);
    }

    #[Route('/ids', name: 'teacher_class_ids', methods: ['GET'])]
    public function ids(#[CurrentUser] User $user, #[MapQueryParameter] string $layout = 'sheet'): Response
    {
        return $this->downloadIds($this->scope($user)->fixedSection, $layout);
    }

    #[Route('/{learner}/id', name: 'teacher_class_id', methods: ['GET'])]
    public function id(#[CurrentUser] User $user, int $learner, #[MapQueryParameter] string $layout = 'sheet'): Response
    {
        return $this->handleIdDownload($this->scope($user), $learner, $layout);
    }

    #[Route('/enroll', name: 'teacher_class_enroll', methods: ['GET', 'POST'])]
    public function enroll(Request $request, #[CurrentUser] User $user): Response
    {
        $scope = $this->scope($user);
        $this->denyAccessUnlessGranted(LearnerVoter::ENROLL, $scope->fixedSection);

        return $this->handleEnroll($request, $scope, $user);
    }

    #[Route('/{learner}', name: 'teacher_class_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user, int $learner): Response
    {
        return $this->renderShow($this->scope($user), $learner);
    }

    #[Route('/{learner}/edit', name: 'teacher_class_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, #[CurrentUser] User $user, int $learner): Response
    {
        return $this->handleEdit($request, $this->scope($user), $learner);
    }

    #[Route('/{learner}/photo', name: 'teacher_class_photo', methods: ['POST'])]
    public function photo(Request $request, #[CurrentUser] User $user, int $learner): Response
    {
        return $this->handlePhoto($request, $this->scope($user), $learner);
    }

    #[Route('/{learner}/photo/remove', name: 'teacher_class_photo_remove', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function photoRemove(#[CurrentUser] User $user, int $learner): Response
    {
        return $this->handlePhotoRemove($this->scope($user), $learner);
    }

    #[Route('/{learner}/enrollment', name: 'teacher_class_enrollment', methods: ['GET', 'POST'])]
    public function enrollmentRecord(Request $request, #[CurrentUser] User $user, int $learner): Response
    {
        return $this->handleEnrollmentRecord($request, $this->scope($user), $learner);
    }

    #[Route('/{learner}/unenroll', name: 'teacher_class_unenroll', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function unenroll(#[CurrentUser] User $user, int $learner): Response
    {
        return $this->handleUnenroll($this->scope($user), $learner);
    }

    #[Route('/{learner}/reset-password', name: 'teacher_class_reset_password', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function resetPassword(#[CurrentUser] User $user, int $learner): Response
    {
        return $this->handleResetPassword($this->scope($user), $learner);
    }

    /** @return LearnerScope with a fixed section, never null */
    private function scope(User $user): LearnerScope
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $section = null === $schoolYear ? null : $this->sections->findAdvisory($user, $schoolYear);
        if (null === $section) {
            throw $this->createAccessDeniedException('You do not advise a section this school year.');
        }
        $this->denyAccessUnlessGranted(LearnerVoter::VIEW_CLASS, $section);

        return new LearnerScope($section->getSchool(), $schoolYear, $section, 'teacher_class', [], 'teacher/_layout.html.twig');
    }
}
