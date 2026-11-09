<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Controller;

use App\Classes\Application\Service\Subject\SubjectScopeKey;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The school's subjects for the current school year (admin and the school's principal). */
#[Route('/schools/{id}/subjects', requirements: ['id' => '\d+'])]
#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]
class SchoolSubjectController extends AbstractSubjectController
{
    private int $schoolId = 0;

    #[Route('', name: 'school_subjects', methods: ['GET'])]
    public function index(School $school): Response
    {
        $this->schoolId = (int) $school->getId();

        return $this->renderList();
    }

    #[Route('/new', name: 'school_subjects_new', methods: ['GET', 'POST'])]
    public function new(Request $request, School $school): Response
    {
        $this->schoolId = (int) $school->getId();

        return $this->handleForm($request, null);
    }

    #[Route(
        '/{subject}/edit',
        name: 'school_subjects_edit',
        requirements: ['subject' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function edit(Request $request, School $school, int $subject): Response
    {
        $this->schoolId = (int) $school->getId();

        return $this->handleForm($request, $subject);
    }

    #[Route('/{subject}/delete', name: 'school_subjects_delete', requirements: ['subject' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"subject-" ~ args["subject"]'), tokenKey: '_token')]
    public function delete(School $school, int $subject): Response
    {
        $this->schoolId = (int) $school->getId();

        return $this->handleDelete($subject);
    }

    #[Route('/deped', name: 'school_subjects_deped', methods: ['POST'])]
    #[IsCsrfTokenValid('deped-subjects', tokenKey: '_token')]
    public function deped(Request $request, School $school): Response
    {
        $this->schoolId = (int) $school->getId();

        return $this->handleDepEd($request);
    }

    #[Route('/bring-back', name: 'school_subjects_bring_back', methods: ['POST'])]
    #[IsCsrfTokenValid('bring-back-subjects', tokenKey: '_token')]
    public function bringBack(School $school): Response
    {
        $this->schoolId = (int) $school->getId();

        return $this->handleBringBack();
    }

    protected function scopeKey(): SubjectScopeKey
    {
        return SubjectScopeKey::school($this->schoolId);
    }

    protected function routes(): string
    {
        return 'school_subjects';
    }

    protected function routeParams(): array
    {
        return ['id' => $this->schoolId];
    }

    protected function layout(): string
    {
        return $this->isGranted('ROLE_ADMIN') ? '@Core/admin/_layout.html.twig' : '@Core/principal/_layout.html.twig';
    }
}
