<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Classes\Application\Query\Command\GetOwnSectionFormCommand;
use App\Classes\Application\UseCase\Command\CreateOwnSectionCommand;
use App\Classes\Domain\Exception\ClassesAccessDeniedException;
use App\Classes\Presentation\Form\SectionFormType;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** On a plan without a principal, a teacher creates the section they advise. */
#[IsGranted(User::ROLE_TEACHER)]
class TeacherSectionController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/teacher/section', name: 'teacher_section_new', methods: ['GET', 'POST'])]
    public function createSection(Request $request, #[CurrentUser] User $user): Response
    {
        $teacherId = (int) $user->getId();
        $result = $this->queryBus->handle(new GetOwnSectionFormCommand($teacherId));
        if (null !== $refusal = $this->refusal($result)) {
            return $refusal;
        }
        /** @var array{school: ?array{id: int, name: string}, grades: list<\App\Schools\Domain\Model\GradeLevel>} $page */
        $page = $result->data();
        if (null === $page['school']) {
            return $this->redirectToRoute('teacher_dashboard');
        }

        $command = new CreateOwnSectionCommand($page['school']['id'], $teacherId);
        $form = $this->createForm(SectionFormType::class, $command, [
            'grades' => $page['grades'],
            'with_adviser' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // A refusal here means another request changed things since the form opened
            $result = $this->useCaseBus->handle($command);

            return $this->refusal($result) ?? $this->created($result);
        }

        return $this->render('@Classes/teacher/section.html.twig', [
            'form' => $form,
            'school' => $page['school'],
            'strands' => SectionFormType::STRANDS,
        ]);
    }

    private function created(Result $result): Response
    {
        /** @var array{label: string} $created */
        $created = $result->data();
        $this->addFlash('success', \sprintf('Created %s. You are its adviser.', $created['label']));

        return $this->redirectToRoute('teacher_dashboard');
    }

    /** The response for a refusal (403, or an error back on the dashboard), or null when $result succeeded. */
    private function refusal(Result $result): ?Response
    {
        $failure = $this->domainFailure($result);
        if (null === $failure) {
            return null;
        }
        if ($failure instanceof ClassesAccessDeniedException) {
            throw $this->createAccessDeniedException($failure->getMessage());
        }
        $this->addFlash('error', $failure->getMessage());

        return $this->redirectToRoute('teacher_dashboard');
    }
}
