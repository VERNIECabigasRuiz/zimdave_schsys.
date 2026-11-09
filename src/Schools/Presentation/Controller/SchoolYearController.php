<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Application\Query\Command\GetSchoolYearFormCommand;
use App\Schools\Application\Query\Command\ListSchoolYearsCommand;
use App\Schools\Application\UseCase\Command\CreateSchoolYearCommand;
use App\Schools\Application\UseCase\Command\MakeSchoolYearCurrentCommand;
use App\Schools\Application\UseCase\Command\SchoolYearDetails;
use App\Schools\Application\UseCase\Command\UpdateSchoolYearCommand;
use App\Schools\Domain\Exception\GradedPeriodRemovalException;
use App\Schools\Domain\Exception\GradingSystemLockedException;
use App\Schools\Presentation\Form\SchoolYearFormType;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @phpstan-import-type SchoolYearData from \App\Schools\Application\Transformer\SchoolYearTransformer
 */
#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin/school-years')]
class SchoolYearController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('', name: 'admin_school_years', methods: ['GET'])]
    public function index(): Response
    {
        $result = $this->queryBus->handle(new ListSchoolYearsCommand());
        $this->domainFailure($result);
        /** @var array{schoolYears: list<SchoolYearData>, openCounts: array<int, int>, schoolCount: int} $list */
        $list = $result->data();

        return $this->render('@Schools/admin/school_years/index.html.twig', [
            'school_years' => $list['schoolYears'],
            'open_counts' => $list['openCounts'],
            'school_count' => $list['schoolCount'],
        ]);
    }

    #[Route('/new', name: 'admin_school_year_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $command = CreateSchoolYearCommand::fromSuggestion($this->schoolYear(null));
        $form = $this->createForm(SchoolYearFormType::class, $command);
        $form->handleRequest($request);

        /** @var array{name: string, periodCount: int, periodName: string}|null $created */
        $created = $form->isSubmitted() && $form->isValid() ? $this->submit($form, $command) : null;
        if (null !== $created) {
            $periodName = strtolower($created['periodName']);
            $this->addFlash('success', \sprintf(
                'School year %s created with %d %ss. Use Edit to set the exact dates of each %s.',
                $created['name'],
                $created['periodCount'],
                $periodName,
                $periodName,
            ));

            return $this->redirectToRoute('admin_school_years');
        }

        return $this->render('@Schools/admin/school_years/form.html.twig', ['form' => $form, 'school_year' => null]);
    }

    #[Route('/{id}/edit', name: 'admin_school_year_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $schoolYear = $this->schoolYear($id);
        $command = UpdateSchoolYearCommand::fromSchoolYear($schoolYear);
        $form = $this->createForm(SchoolYearFormType::class, $command, ['with_periods' => true]);
        $form->handleRequest($request);

        /** @var array{name: string}|null $saved */
        $saved = $form->isSubmitted() && $form->isValid() ? $this->submit($form, $command) : null;
        if (null !== $saved) {
            $this->addFlash('success', \sprintf('School year %s saved.', $saved['name']));

            return $this->redirectToRoute('admin_school_years');
        }

        return $this->render('@Schools/admin/school_years/form.html.twig', [
            'form' => $form,
            'school_year' => $schoolYear,
        ]);
    }

    #[Route(
        '/{id}/make-current',
        name: 'admin_school_year_make_current',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(new Expression('"school-year-" ~ args["id"]'), tokenKey: '_token')]
    public function makeCurrent(int $id): Response
    {
        $result = $this->useCaseBus->handle(new MakeSchoolYearCurrentCommand($id));
        if (null !== $this->domainFailure($result)) {
            throw $this->createNotFoundException();
        }
        /** @var array{name: string} $schoolYear */
        $schoolYear = $result->data();
        $this->addFlash('success', \sprintf('%s is now the current school year.', $schoolYear['name']));

        return $this->redirectToRoute('admin_school_years');
    }

    /** @return SchoolYearData */
    private function schoolYear(?int $id): array
    {
        $result = $this->queryBus->handle(new GetSchoolYearFormCommand($id));
        if (null !== $this->domainFailure($result)) {
            throw $this->createNotFoundException();
        }
        /** @var SchoolYearData $schoolYear */
        $schoolYear = $result->data();

        return $schoolYear;
    }

    /**
     * Sends the form's command through the bus. Gives the result's data, or null after putting the
     * refusal under its field (the grading system, or the name taken a moment ago).
     *
     * @param FormInterface<SchoolYearDetails> $form
     */
    private function submit(FormInterface $form, SchoolYearDetails $command): mixed
    {
        $result = $this->useCaseBus->handle($command);
        $failure = $this->domainFailure($result);
        if (null !== $failure) {
            $onSystem = $failure instanceof GradingSystemLockedException
                || $failure instanceof GradedPeriodRemovalException;
            $form->get($onSystem ? 'gradingSystem' : 'name')->addError(new FormError($failure->getMessage()));

            return null;
        }

        return $result->data();
    }
}
