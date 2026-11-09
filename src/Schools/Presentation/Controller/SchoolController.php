<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Application\Query\Command\GetSchoolFormCommand;
use App\Schools\Application\Query\Command\ListSchoolsCommand;
use App\Schools\Application\UseCase\Command\CreateSchoolCommand;
use App\Schools\Application\UseCase\Command\SchoolDetails;
use App\Schools\Application\UseCase\Command\ToggleSchoolActiveCommand;
use App\Schools\Application\UseCase\Command\UpdateSchoolCommand;
use App\Schools\Domain\Exception\PrincipalBlocksPlanException;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Presentation\Form\SchoolFormType;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @phpstan-import-type SchoolData from \App\Schools\Application\Transformer\SchoolTransformer
 * @phpstan-type SchoolForm array{school: ?SchoolData, plans: list<array{id: int, name: string,
 *     teachersPerSeat: ?int, studentsPerSeat: ?int}>, basicPlanId: ?int}
 */
#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin/schools')]
class SchoolController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('', name: 'admin_schools', methods: ['GET'])]
    public function index(#[MapQueryParameter] ?int $sy = null): Response
    {
        $result = $this->queryBus->handle(new ListSchoolsCommand($sy));
        if (null !== $this->domainFailure($result)) {
            throw $this->createNotFoundException('School year not found.');
        }
        /** @var array{schools: list<SchoolData>, schoolYears: list<mixed>, schoolYear: mixed, principals: mixed,
         *     teacherCounts: array<int, int>, previousYear: mixed, today: \DateTimeImmutable} $list */
        $list = $result->data();

        return $this->render('@Schools/admin/schools/index.html.twig', [
            'schools' => $list['schools'],
            'school_years' => $list['schoolYears'],
            'school_year' => $list['schoolYear'],
            'principals' => $list['principals'],
            'teacher_counts' => $list['teacherCounts'],
            'today' => $list['today'],
            'previous_year' => $list['previousYear'],
        ]);
    }

    #[Route('/new', name: 'admin_schools_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $options = $this->schoolForm(null);
        $command = new CreateSchoolCommand();
        $command->planId = $options['basicPlanId'];
        $form = $this->createForm(SchoolFormType::class, $command, ['plans' => $options['plans']]);
        $form->handleRequest($request);

        /** @var array{name: string}|null $created */
        $created = $form->isSubmitted() && $form->isValid() ? $this->submit($form, $command) : null;
        if (null !== $created) {
            $this->addFlash('success', \sprintf('%s created.', $created['name']));

            return $this->redirectToRoute('admin_schools');
        }

        return $this->render('@Schools/admin/schools/form.html.twig', ['form' => $form, 'school' => null]);
    }

    #[Route('/{id}/edit', name: 'admin_schools_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $options = $this->schoolForm($id);
        /** @var SchoolData $school */
        $school = $options['school'];
        $command = UpdateSchoolCommand::fromSchool($school);
        $form = $this->createForm(SchoolFormType::class, $command, ['plans' => $options['plans']]);
        $form->handleRequest($request);

        /** @var array{name: string}|null $saved */
        $saved = $form->isSubmitted() && $form->isValid() ? $this->submit($form, $command) : null;
        if (null !== $saved) {
            $this->addFlash('success', \sprintf('%s saved.', $saved['name']));

            return $this->redirectToRoute('admin_schools');
        }

        return $this->render('@Schools/admin/schools/form.html.twig', ['form' => $form, 'school' => $school]);
    }

    #[Route('/{id}/toggle', name: 'admin_schools_toggle', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"school-" ~ args["id"]'), tokenKey: '_token')]
    public function toggle(int $id): Response
    {
        $result = $this->useCaseBus->handle(new ToggleSchoolActiveCommand($id));
        if (null !== $this->domainFailure($result)) {
            throw $this->createNotFoundException();
        }
        /** @var array{name: string, active: bool} $school */
        $school = $result->data();
        $this->addFlash('success', \sprintf(
            '%s is now %s.',
            $school['name'],
            $school['active'] ? 'active' : 'inactive',
        ));

        return $this->redirectToRoute('admin_schools');
    }

    /** @return SchoolForm */
    private function schoolForm(?int $id): array
    {
        $result = $this->queryBus->handle(new GetSchoolFormCommand($id));
        if ($this->domainFailure($result) instanceof SchoolNotFoundException) {
            throw $this->createNotFoundException();
        }
        /** @var SchoolForm $options */
        $options = $result->data();

        return $options;
    }

    /**
     * Sends the form's command through the bus. Gives the result's data, or null after putting the
     * refusal under its field (the plan, or the School ID taken or the school deleted a moment ago).
     *
     * @param FormInterface<SchoolDetails> $form
     */
    private function submit(FormInterface $form, SchoolDetails $command): mixed
    {
        $result = $this->useCaseBus->handle($command);
        $failure = $this->domainFailure($result);
        if (null !== $failure) {
            $field = $failure instanceof PrincipalBlocksPlanException ? 'plan' : 'schoolId';
            $form->get($field)->addError(new FormError($failure->getMessage()));

            return null;
        }

        return $result->data();
    }
}
