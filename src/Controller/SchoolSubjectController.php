<?php

namespace App\Controller;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Security\Voter\SchoolVoter;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The subjects a school offers this S.Y. — admin for any school, principal for their own. */
#[Route('/schools/{id}/subjects', requirements: ['id' => '\d+'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolSubjectController extends AbstractSubjectController
{
    #[Route('', name: 'school_subjects', methods: ['GET'])]
    public function index(School $school): Response
    {
        if (null === $schoolYear = $this->schoolYears->findCurrent()) {
            return $this->render('school_subjects/index.html.twig', [
                'school' => $school, 'school_year' => null, 'layout' => $this->layout(), 'routes' => 'school_subjects', 'route_params' => ['id' => $school->getId()],
            ]);
        }

        return $this->renderList($this->scope($school, $schoolYear));
    }

    #[Route('/new', name: 'school_subjects_new', methods: ['GET', 'POST'])]
    public function new(Request $request, School $school): Response
    {
        $scope = $this->scope($school);

        return $this->handleForm($request, $scope, $this->newSubject($request, $scope));
    }

    #[Route('/{subject}/edit', name: 'school_subjects_edit', requirements: ['subject' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, School $school, int $subject): Response
    {
        $scope = $this->scope($school);

        return $this->handleForm($request, $scope, $this->requireSubject($scope, $subject));
    }

    #[Route('/{subject}/delete', name: 'school_subjects_delete', requirements: ['subject' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"subject-" ~ args["subject"]'), tokenKey: '_token')]
    public function delete(School $school, int $subject): Response
    {
        $scope = $this->scope($school);

        return $this->handleDelete($scope, $this->requireSubject($scope, $subject));
    }

    #[Route('/deped', name: 'school_subjects_deped', methods: ['POST'])]
    #[IsCsrfTokenValid('deped-subjects', tokenKey: '_token')]
    public function deped(Request $request, School $school): Response
    {
        return $this->handleDepEd($request, $this->scope($school));
    }

    #[Route('/bring-back', name: 'school_subjects_bring_back', methods: ['POST'])]
    #[IsCsrfTokenValid('bring-back-subjects', tokenKey: '_token')]
    public function bringBack(School $school): Response
    {
        return $this->handleBringBack($this->scope($school));
    }

    private function scope(School $school, ?SchoolYear $schoolYear = null): SubjectScope
    {
        $schoolYear ??= $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');

        return new SubjectScope(
            $school,
            $schoolYear,
            $school->getType()?->gradeLevels() ?? [],
            'school_subjects',
            ['id' => $school->getId()],
            $this->layout(),
        );
    }

    private function layout(): string
    {
        return $this->isGranted('ROLE_ADMIN') ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig';
    }
}
