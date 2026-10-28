<?php

namespace App\Controller;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Security\Voter\SchoolVoter;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The learners of a school this S.Y. — admin for any school, principal for their own. */
#[Route('/schools/{id}', requirements: ['id' => '\d+', 'learner' => '\d+', 'section' => '\d+'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolLearnerController extends AbstractLearnerController
{
    #[Route('/learners', name: 'school_learners', methods: ['GET'])]
    public function index(
        School $school,
        #[MapQueryParameter] ?string $q = null,
        #[MapQueryParameter(flags: \FILTER_NULL_ON_FAILURE)] ?int $section = null,
    ): Response {
        if (null === $schoolYear = $this->schoolYears->findCurrent()) {
            return $this->render('learners/index.html.twig', [
                'school' => $school, 'school_year' => null, 'layout' => $this->layout(), 'routes' => 'school_learners', 'route_params' => ['id' => $school->getId()],
            ]);
        }

        return $this->renderIndex($this->scope($school, $schoolYear), $q, $section);
    }

    #[Route('/learners/enroll', name: 'school_learners_enroll', methods: ['GET', 'POST'])]
    public function enroll(Request $request, School $school, #[CurrentUser] User $user): Response
    {
        return $this->handleEnroll($request, $this->scope($school), $user);
    }

    #[Route('/learners/{learner}', name: 'school_learners_show', methods: ['GET'])]
    public function show(School $school, int $learner): Response
    {
        return $this->renderShow($this->scope($school), $learner);
    }

    #[Route('/learners/{learner}/edit', name: 'school_learners_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, School $school, int $learner): Response
    {
        return $this->handleEdit($request, $this->scope($school), $learner);
    }

    #[Route('/learners/{learner}/move', name: 'school_learners_move', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function move(Request $request, School $school, int $learner): Response
    {
        return $this->handleMove($request, $this->scope($school), $learner);
    }

    #[Route('/learners/{learner}/unenroll', name: 'school_learners_unenroll', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function unenroll(School $school, int $learner): Response
    {
        return $this->handleUnenroll($this->scope($school), $learner);
    }

    #[Route('/learners/{learner}/reset-password', name: 'school_learners_reset_password', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"learner-" ~ args["learner"]'), tokenKey: '_token')]
    public function resetPassword(School $school, int $learner): Response
    {
        return $this->handleResetPassword($this->scope($school), $learner);
    }

    #[Route('/sections/{section}/learners', name: 'school_learners_class', methods: ['GET'])]
    public function classList(School $school, int $section): Response
    {
        $scope = $this->scope($school);

        return $this->renderClassList($scope, $this->requireSection($scope, $section));
    }

    private function scope(School $school, ?SchoolYear $schoolYear = null): LearnerScope
    {
        $schoolYear ??= $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');

        return new LearnerScope($school, $schoolYear, null, 'school_learners', ['id' => $school->getId()], $this->layout());
    }

    private function layout(): string
    {
        return $this->isGranted('ROLE_ADMIN') ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig';
    }
}
