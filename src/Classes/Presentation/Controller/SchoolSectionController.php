<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Controller;

use App\Classes\Application\Query\Command\GetSectionFormCommand;
use App\Classes\Application\Query\Command\ListSectionsCommand;
use App\Classes\Application\UseCase\Command\BringBackSectionsCommand;
use App\Classes\Application\UseCase\Command\CreateSectionCommand;
use App\Classes\Application\UseCase\Command\DeleteSectionCommand;
use App\Classes\Application\UseCase\Command\SectionDetails;
use App\Classes\Application\UseCase\Command\UpdateSectionCommand;
use App\Classes\Presentation\Form\SectionFormType;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Domain\Exception\DomainException;
use App\Core\Presentation\Controller\BusController;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Model\School;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The school's sections for the current school year (admin and the school's principal).
 *
 * @phpstan-import-type SectionData from \App\Classes\Application\Transformer\SectionTransformer
 *
 * @phpstan-type SectionForm array{section: ?SectionData, schoolYear: array{name: string}, refusal: ?string,
 *     grades: list<\App\Schools\Domain\Model\GradeLevel>, teachers: list<array{id: int, fullName: string}>}
 */
#[Route('/schools/{id}/sections', requirements: ['id' => '\d+'])]
#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]
class SchoolSectionController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('', name: 'school_sections', methods: ['GET'])]
    public function index(School $school): Response
    {
        $result = $this->queryBus->handle(new ListSectionsCommand((int) $school->getId()));
        $this->domainFailure($result);
        /** @var array{schoolYear: ?array{name: string}, groups: array<string, list<SectionData>>,
         *     enrolled: array<int, int>, refusal: ?string, bringBackFrom: ?array{name: string}} $list */
        $list = $result->data();

        return $this->render('@Classes/school_sections/index.html.twig', [
            'school' => $school,
            'school_year' => $list['schoolYear'],
            'groups' => $list['groups'],
            'enrolled' => $list['enrolled'],
            'refusal' => $list['refusal'],
            'bring_back_from' => $list['bringBackFrom'],
        ]);
    }

    #[Route('/new', name: 'school_sections_new', methods: ['GET', 'POST'])]
    public function new(Request $request, School $school): Response
    {
        $page = $this->sectionForm($school, null);
        if (null !== $page['refusal']) {
            return $this->refused($school, $page['refusal']);
        }
        $command = new CreateSectionCommand((int) $school->getId());

        return $this->handleForm($request, $school, $page, $command, 'Created %s.');
    }

    #[Route(
        '/{section}/edit',
        name: 'school_sections_edit',
        requirements: ['section' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function edit(Request $request, School $school, int $section): Response
    {
        $page = $this->sectionForm($school, $section);
        if (null !== $page['refusal']) {
            return $this->refused($school, $page['refusal']);
        }
        \assert(null !== $page['section']);
        $command = UpdateSectionCommand::fromSection((int) $school->getId(), $page['section']);

        return $this->handleForm($request, $school, $page, $command, 'Saved %s.');
    }

    #[Route('/{section}/delete', name: 'school_sections_delete', requirements: ['section' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"section-" ~ args["section"]'), tokenKey: '_token')]
    public function delete(School $school, int $section): Response
    {
        $result = $this->useCaseBus->handle(new DeleteSectionCommand((int) $school->getId(), $section));
        if (null !== $refusal = $this->refusal($result)) {
            $this->addFlash('error', $refusal->getMessage());
        } else {
            /** @var array{label: string} $deleted */
            $deleted = $result->data();
            $this->addFlash('success', \sprintf('Deleted %s.', $deleted['label']));
        }

        return $this->redirectToList($school);
    }

    #[Route('/bring-back', name: 'school_sections_bring_back', methods: ['POST'])]
    #[IsCsrfTokenValid('bring-back-sections', tokenKey: '_token')]
    public function bringBack(School $school): Response
    {
        $result = $this->useCaseBus->handle(new BringBackSectionsCommand((int) $school->getId()));
        if (null !== $refusal = $this->refusal($result)) {
            $this->addFlash('error', $refusal->getMessage());
        } else {
            /** @var array{count: int, from: string} $back */
            $back = $result->data();
            $this->addFlash('success', \sprintf(
                'Brought back %d section(s) from S.Y. %s. Assign their advisers.',
                $back['count'],
                $back['from'],
            ));
        }

        return $this->redirectToList($school);
    }

    /** @param SectionForm $page */
    private function handleForm(
        Request $request,
        School $school,
        array $page,
        SectionDetails $command,
        string $savedMessage,
    ): Response {
        $form = $this->createForm(SectionFormType::class, $command, [
            'grades' => $page['grades'],
            'teachers' => $page['teachers'],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $result = $this->useCaseBus->handle($command);
            if (null !== $refusal = $this->refusal($result)) {
                $this->addFlash('error', $refusal->getMessage());
            } else {
                /** @var array{label: string} $saved */
                $saved = $result->data();
                $this->addFlash('success', \sprintf($savedMessage, $saved['label']));
            }

            return $this->redirectToList($school);
        }

        return $this->render('@Classes/school_sections/form.html.twig', [
            'form' => $form,
            'school' => $school,
            'section' => $page['section'] ?? ['id' => null, 'schoolYear' => $page['schoolYear']],
            'strands' => SectionFormType::STRANDS,
        ]);
    }

    /** @return SectionForm */
    private function sectionForm(School $school, ?int $sectionId): array
    {
        $result = $this->queryBus->handle(new GetSectionFormCommand((int) $school->getId(), $sectionId));
        $this->refusal($result);
        /** @var SectionForm $page */
        $page = $result->data();

        return $page;
    }

    /**
     * The refusal to show, or null when $result succeeded. A missing section or school year is a 404.
     */
    private function refusal(Result $result): ?DomainException
    {
        $failure = $this->domainFailure($result);
        if ($failure instanceof SectionNotFoundException || $failure instanceof SchoolYearNotFoundException) {
            throw $this->createNotFoundException($failure->getMessage());
        }

        return $failure;
    }

    private function refused(School $school, string $message): Response
    {
        $this->addFlash('error', $message);

        return $this->redirectToList($school);
    }

    private function redirectToList(School $school): Response
    {
        return $this->redirectToRoute('school_sections', ['id' => $school->getId()]);
    }
}
