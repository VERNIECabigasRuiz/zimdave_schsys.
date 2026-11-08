<?php

declare(strict_types=1);

namespace App\Learners\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Learners\Application\Query\Command\GetAdvisoryCommand;
use App\Learners\Application\Query\Command\GetLearnerIdCardCommand;
use App\Learners\Application\Query\Command\GetSectionIdCardsCommand;
use App\Learners\Application\Query\Command\GetSf1FileCommand;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The adviser's class pages: the learners of the section they advise this school year. */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher/class', requirements: ['learner' => '\d+'])]
class TeacherClassController extends AbstractLearnerController
{
    #[Route('', name: 'teacher_class', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $page = $this->page($user);

        return $this->renderClassList($page, (int) $page->fixedSectionId);
    }

    #[Route('/enroll', name: 'teacher_class_enroll', methods: ['GET', 'POST'])]
    public function enroll(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->handleEnroll($request, $this->page($user));
    }

    #[Route('/{learner}', name: 'teacher_class_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user, int $learner): Response
    {
        return $this->renderShow($this->page($user), $learner);
    }

    #[Route('/{learner}/edit', name: 'teacher_class_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, #[CurrentUser] User $user, int $learner): Response
    {
        return $this->handleEdit($request, $this->page($user), $learner);
    }

    #[Route('/{learner}/photo', name: 'teacher_class_photo', methods: ['POST'])]
    public function photo(Request $request, #[CurrentUser] User $user, int $learner): Response
    {
        return $this->handlePhoto($request, $this->page($user), $learner);
    }

    #[Route('/{learner}/photo/remove', name: 'teacher_class_photo_remove', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function photoRemove(#[CurrentUser] User $user, int $learner): Response
    {
        return $this->handlePhotoRemove($this->page($user), $learner);
    }

    #[Route('/{learner}/enrollment', name: 'teacher_class_enrollment', methods: ['GET', 'POST'])]
    public function enrollmentRecord(Request $request, #[CurrentUser] User $user, int $learner): Response
    {
        return $this->handleEnrollmentRecord($request, $this->page($user), $learner);
    }

    #[Route('/{learner}/unenroll', name: 'teacher_class_unenroll', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function unenroll(#[CurrentUser] User $user, int $learner): Response
    {
        return $this->handleUnenroll($this->page($user), $learner);
    }

    #[Route('/{learner}/reset-password', name: 'teacher_class_reset_password', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function resetPassword(#[CurrentUser] User $user, int $learner): Response
    {
        return $this->handleResetPassword($this->page($user), $learner);
    }

    /** The class's SF1 (School Register) as an .xls file laid out like LIS's. */
    #[Route('/sf1', name: 'teacher_class_sf1', methods: ['GET'])]
    public function sf1(#[CurrentUser] User $user): Response
    {
        $page = $this->page($user);

        return $this->download(
            new GetSf1FileCommand($page->actorId, $page->schoolId, $page->fixedSectionId, (int) $page->fixedSectionId),
            'application/vnd.ms-excel',
        );
    }

    /** ID cards of every learner in the class, as a PDF. */
    #[Route('/ids', name: 'teacher_class_ids', methods: ['GET'])]
    public function classIds(#[CurrentUser] User $user, #[MapQueryParameter] string $layout = 'sheet'): Response
    {
        $page = $this->page($user);

        return $this->download(
            new GetSectionIdCardsCommand(
                $page->actorId,
                $page->schoolId,
                $page->fixedSectionId,
                (int) $page->fixedSectionId,
                $layout,
            ),
            'application/pdf',
        );
    }

    /** One learner's ID card, e.g. to replace a lost one. */
    #[Route('/{learner}/id', name: 'teacher_class_id', methods: ['GET'])]
    public function learnerId(
        #[CurrentUser] User $user,
        int $learner,
        #[MapQueryParameter] string $layout = 'sheet',
    ): Response {
        $page = $this->page($user);

        return $this->download(
            new GetLearnerIdCardCommand($page->actorId, $page->schoolId, $page->fixedSectionId, $learner, $layout),
            'application/pdf',
        );
    }

    /** The adviser's own section this school year; without one the class pages are refused. */
    private function page(User $user): LearnerPage
    {
        /** @var array{schoolId: int, sectionId: int} $advisory */
        $advisory = $this->ask(new GetAdvisoryCommand((int) $user->getId()));

        return new LearnerPage(
            (int) $user->getId(),
            $advisory['schoolId'],
            $advisory['sectionId'],
            'teacher_class',
            [],
            'teacher/_layout.html.twig',
        );
    }
}
