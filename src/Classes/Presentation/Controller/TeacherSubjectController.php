<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Classes\Application\Query\Command\GetSubjectScopeCommand;
use App\Classes\Application\Service\Subject\SubjectScopeKey;
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
    private int $teacherId = 0;

    #[Route('', name: 'teacher_subjects', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        $this->teacherId = (int) $user->getId();

        return $this->renderList();
    }

    #[Route('/new', name: 'teacher_subjects_new', methods: ['GET', 'POST'])]
    public function new(Request $request, #[CurrentUser] User $user): Response
    {
        $this->teacherId = (int) $user->getId();

        return $this->handleForm($request, null);
    }

    #[Route(
        '/{subject}/edit',
        name: 'teacher_subjects_edit',
        requirements: ['subject' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function edit(Request $request, #[CurrentUser] User $user, int $subject): Response
    {
        $this->teacherId = (int) $user->getId();

        return $this->handleForm($request, $subject);
    }

    #[Route(
        '/{subject}/delete',
        name: 'teacher_subjects_delete',
        requirements: ['subject' => '\d+'],
        methods: ['POST'],
    )]
    public function delete(Request $request, #[CurrentUser] User $user, int $subject): Response
    {
        return $this->checked($request, $user, 'subject-' . $subject) ?? $this->handleDelete($subject);
    }

    #[Route('/deped', name: 'teacher_subjects_deped', methods: ['POST'])]
    public function deped(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->checked($request, $user, 'deped-subjects') ?? $this->handleDepEd($request);
    }

    #[Route('/bring-back', name: 'teacher_subjects_bring_back', methods: ['POST'])]
    public function bringBack(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->checked($request, $user, 'bring-back-subjects') ?? $this->handleBringBack();
    }

    protected function scopeKey(): SubjectScopeKey
    {
        return SubjectScopeKey::teacher($this->teacherId);
    }

    protected function routes(): string
    {
        return 'teacher_subjects';
    }

    protected function routeParams(): array
    {
        return [];
    }

    protected function layout(): string
    {
        return 'teacher/_layout.html.twig';
    }

    /**
     * Checks the plan (403) before the CSRF token, so a teacher on a principal's plan always gets 403.
     * Gives the list when the teacher has no section yet, or null when the action may go on.
     */
    private function checked(Request $request, User $user, string $tokenId): ?Response
    {
        $this->teacherId = (int) $user->getId();
        if (null !== $this->refusal($this->queryBus->handle(new GetSubjectScopeCommand($this->scopeKey())))) {
            return $this->redirectToList();
        }
        if (!$this->isCsrfTokenValid($tokenId, $request->getPayload()->getString('_token'))) {
            throw new InvalidCsrfTokenException('Invalid CSRF token.');
        }

        return null;
    }
}
