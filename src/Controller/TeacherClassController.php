<?php

namespace App\Controller;

use App\Entity\User;
use App\Security\Voter\LearnerVoter;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
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
