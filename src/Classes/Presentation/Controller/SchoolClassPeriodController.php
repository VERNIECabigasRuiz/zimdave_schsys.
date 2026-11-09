<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Controller;

use App\Classes\Application\Query\Command\GetClassPeriodFormCommand;
use App\Classes\Application\Query\Command\ListClassPeriodsCommand;
use App\Classes\Application\UseCase\Command\ClassPeriodDetails;
use App\Classes\Application\UseCase\Command\CreateClassPeriodCommand;
use App\Classes\Application\UseCase\Command\DeleteClassPeriodCommand;
use App\Classes\Application\UseCase\Command\UpdateClassPeriodCommand;
use App\Classes\Domain\Exception\ClassPeriodNotFoundException;
use App\Classes\Presentation\Form\ClassPeriodFormType;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Domain\Exception\DomainException;
use App\Core\Presentation\Controller\BusController;
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
 * The periods and breaks of the school's day this school year (admin and the school's principal).
 *
 * @phpstan-import-type ClassPeriodData from \App\Classes\Application\Transformer\ClassPeriodTransformer
 *
 * @phpstan-type ClassPeriodForm array{period: ?ClassPeriodData, schoolYear: array{name: string}, refusal: ?string}
 */
#[Route('/schools/{id}/class-periods', requirements: ['id' => '\d+'])]
#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]
class SchoolClassPeriodController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('', name: 'school_class_periods', methods: ['GET'])]
    public function index(School $school): Response
    {
        $result = $this->queryBus->handle(new ListClassPeriodsCommand((int) $school->getId()));
        $this->domainFailure($result);
        /** @var array{schoolYear: ?array{name: string}, periods: list<ClassPeriodData>, numbers: array<int, int>,
         *     refusal: ?string} $list */
        $list = $result->data();

        return $this->render('@Classes/school_class_periods/index.html.twig', [
            'school' => $school,
            'school_year' => $list['schoolYear'],
            'layout' => $this->layout(),
            'periods' => $list['periods'],
            'numbers' => $list['numbers'],
            'refusal' => $list['refusal'],
        ]);
    }

    #[Route('/new', name: 'school_class_periods_new', methods: ['GET', 'POST'])]
    public function new(Request $request, School $school): Response
    {
        $page = $this->periodForm($school, null);

        return $this->handleForm(
            $request,
            $school,
            $page,
            new CreateClassPeriodCommand((int) $school->getId()),
            'Added %s.',
        );
    }

    #[Route(
        '/{period}/edit',
        name: 'school_class_periods_edit',
        requirements: ['period' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function edit(Request $request, School $school, int $period): Response
    {
        $page = $this->periodForm($school, $period);
        \assert(null !== $page['period']);
        $command = UpdateClassPeriodCommand::fromPeriod((int) $school->getId(), $page['period']);

        return $this->handleForm($request, $school, $page, $command, 'Saved %s.');
    }

    #[Route(
        '/{period}/delete',
        name: 'school_class_periods_delete',
        requirements: ['period' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(new Expression('"class-period-" ~ args["period"]'), tokenKey: '_token')]
    public function delete(School $school, int $period): Response
    {
        $result = $this->useCaseBus->handle(new DeleteClassPeriodCommand((int) $school->getId(), $period));
        if (null !== $refusal = $this->refusal($result)) {
            $this->addFlash('error', $refusal->getMessage());
        } else {
            /** @var array{label: string} $deleted */
            $deleted = $result->data();
            $this->addFlash('success', \sprintf('Deleted %s.', $deleted['label']));
        }

        return $this->redirectToList($school);
    }

    /** @param ClassPeriodForm $page */
    private function handleForm(
        Request $request,
        School $school,
        array $page,
        ClassPeriodDetails $command,
        string $savedMessage,
    ): Response {
        if (null !== $page['refusal']) {
            $this->addFlash('error', $page['refusal']);

            return $this->redirectToList($school);
        }

        $form = $this->createForm(ClassPeriodFormType::class, $command);
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

        return $this->render('@Classes/school_class_periods/form.html.twig', [
            'school' => $school,
            'school_year' => $page['schoolYear'],
            'layout' => $this->layout(),
            'form' => $form,
            'period' => ['id' => $page['period']['id'] ?? null],
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** @return ClassPeriodForm */
    private function periodForm(School $school, ?int $periodId): array
    {
        $result = $this->queryBus->handle(new GetClassPeriodFormCommand((int) $school->getId(), $periodId));
        $this->refusal($result);
        /** @var ClassPeriodForm $page */
        $page = $result->data();

        return $page;
    }

    /** The refusal to show, or null when $result succeeded. A missing period or school year is a 404. */
    private function refusal(Result $result): ?DomainException
    {
        $failure = $this->domainFailure($result);
        if ($failure instanceof ClassPeriodNotFoundException || $failure instanceof SchoolYearNotFoundException) {
            throw $this->createNotFoundException($failure->getMessage());
        }

        return $failure;
    }

    private function redirectToList(School $school): Response
    {
        return $this->redirectToRoute('school_class_periods', ['id' => $school->getId()]);
    }

    private function layout(): string
    {
        return $this->isGranted('ROLE_ADMIN') ? '@Core/admin/_layout.html.twig' : '@Core/principal/_layout.html.twig';
    }
}
