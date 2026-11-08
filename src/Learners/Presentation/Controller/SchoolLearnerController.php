<?php

declare(strict_types=1);

namespace App\Learners\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The school's learner pages, for its principal and administrators. */
#[Route('/schools/{id}', requirements: ['id' => '\d+', 'learner' => '\d+', 'section' => '\d+'])]
#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]
class SchoolLearnerController extends AbstractLearnerController
{
    #[Route('/learners', name: 'school_learners', methods: ['GET'])]
    public function index(
        School $school,
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter(flags: \FILTER_NULL_ON_FAILURE)] ?int $section = null,
        #[MapQueryParameter(flags: \FILTER_NULL_ON_FAILURE)] ?int $page = null,
    ): Response {
        return $this->renderIndex($this->page($school, $user), $q, $section, $page ?? 1);
    }

    #[Route('/learners/enroll', name: 'school_learners_enroll', methods: ['GET', 'POST'])]
    public function enroll(Request $request, School $school, #[CurrentUser] User $user): Response
    {
        return $this->handleEnroll($request, $this->page($school, $user));
    }

    #[Route('/learners/{learner}', name: 'school_learners_show', methods: ['GET'])]
    public function show(
        School $school,
        int $learner,
        #[CurrentUser] User $user,
        #[MapQueryParameter(name: 'to_school', flags: \FILTER_NULL_ON_FAILURE)] ?int $toSchool = null,
    ): Response {
        return $this->renderShow($this->page($school, $user), $learner, $toSchool);
    }

    #[Route('/learners/{learner}/edit', name: 'school_learners_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, School $school, int $learner, #[CurrentUser] User $user): Response
    {
        return $this->handleEdit($request, $this->page($school, $user), $learner);
    }

    #[Route('/learners/{learner}/photo', name: 'school_learners_photo', methods: ['POST'])]
    public function photo(Request $request, School $school, int $learner, #[CurrentUser] User $user): Response
    {
        return $this->handlePhoto($request, $this->page($school, $user), $learner);
    }

    #[Route('/learners/{learner}/photo/remove', name: 'school_learners_photo_remove', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function photoRemove(School $school, int $learner, #[CurrentUser] User $user): Response
    {
        return $this->handlePhotoRemove($this->page($school, $user), $learner);
    }

    #[Route('/learners/{learner}/enrollment', name: 'school_learners_enrollment', methods: ['GET', 'POST'])]
    public function enrollmentRecord(
        Request $request,
        School $school,
        int $learner,
        #[CurrentUser] User $user,
    ): Response {
        return $this->handleEnrollmentRecord($request, $this->page($school, $user), $learner);
    }

    #[Route('/learners/{learner}/move', name: 'school_learners_move', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function move(Request $request, School $school, int $learner, #[CurrentUser] User $user): Response
    {
        return $this->handleMove($request, $this->page($school, $user), $learner);
    }

    #[Route('/learners/{learner}/transfer', name: 'school_learners_transfer', methods: ['POST'])]
    #[IsGranted(User::ROLE_ADMIN)]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function transfer(Request $request, School $school, int $learner, #[CurrentUser] User $user): Response
    {
        return $this->handleTransfer($request, $this->page($school, $user), $learner);
    }

    #[Route('/learners/{learner}/unenroll', name: 'school_learners_unenroll', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function unenroll(School $school, int $learner, #[CurrentUser] User $user): Response
    {
        return $this->handleUnenroll($this->page($school, $user), $learner);
    }

    #[Route('/learners/{learner}/reset-password', name: 'school_learners_reset_password', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function resetPassword(School $school, int $learner, #[CurrentUser] User $user): Response
    {
        return $this->handleResetPassword($this->page($school, $user), $learner);
    }

    #[Route('/sections/{section}/learners', name: 'school_learners_class', methods: ['GET'])]
    public function classList(School $school, int $section, #[CurrentUser] User $user): Response
    {
        return $this->renderClassList($this->page($school, $user), $section);
    }

    private function page(School $school, User $user): LearnerPage
    {
        return new LearnerPage(
            (int) $user->getId(),
            (int) $school->getId(),
            null,
            'school_learners',
            ['id' => $school->getId()],
            $this->isGranted(User::ROLE_ADMIN) ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig',
        );
    }
}
