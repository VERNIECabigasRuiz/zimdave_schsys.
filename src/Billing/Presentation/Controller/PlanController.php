<?php

declare(strict_types=1);

namespace App\Billing\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Billing\Application\Query\Command\GetPlanCommand;
use App\Billing\Application\Query\Command\ListPlansCommand;
use App\Billing\Application\UseCase\Command\CreatePlanCommand;
use App\Billing\Application\UseCase\Command\DeletePlanCommand;
use App\Billing\Application\UseCase\Command\UpdatePlanCommand;
use App\Billing\Domain\Exception\PlanNotFoundException;
use App\Billing\Presentation\Form\PlanFormType;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @phpstan-import-type PlanData from \App\Billing\Application\Transformer\PlanTransformer
 */
#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin/plans')]
class PlanController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('', name: 'admin_plans', methods: ['GET'])]
    public function index(): Response
    {
        $result = $this->queryBus->handle(new ListPlansCommand());
        $this->domainFailure($result);
        /** @var array{plans: list<PlanData>, schoolCounts: array<int, int>} $list */
        $list = $result->data();

        return $this->render('@Billing/admin/plans/index.html.twig', [
            'plans' => $list['plans'],
            'school_counts' => $list['schoolCounts'],
        ]);
    }

    #[Route('/new', name: 'admin_plans_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $command = new CreatePlanCommand();
        $form = $this->createForm(PlanFormType::class, $command);
        $form->handleRequest($request);

        /** @var array{name: string}|null $created */
        $created = $form->isSubmitted() && $form->isValid() ? $this->submit($form, $command) : null;
        if (null !== $created) {
            $this->addFlash('success', \sprintf('Plan %s created.', $created['name']));

            return $this->redirectToRoute('admin_plans');
        }

        return $this->render('@Billing/admin/plans/form.html.twig', ['form' => $form, 'plan' => null]);
    }

    #[Route('/{id}/edit', name: 'admin_plans_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, int $id): Response
    {
        $plan = $this->plan($id);
        $command = UpdatePlanCommand::fromPlan($plan);
        $form = $this->createForm(PlanFormType::class, $command);
        $form->handleRequest($request);

        /** @var PlanData|null $saved */
        $saved = $form->isSubmitted() && $form->isValid() ? $this->submit($form, $command) : null;
        if (null !== $saved) {
            $this->addFlash('success', \sprintf('Plan %s saved.', $saved['name']));

            return $this->redirectToRoute('admin_plans');
        }

        return $this->render('@Billing/admin/plans/form.html.twig', ['form' => $form, 'plan' => $plan]);
    }

    #[Route('/{id}/delete', name: 'admin_plans_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"plan-" ~ args["id"]'), tokenKey: '_token')]
    public function delete(int $id): Response
    {
        $result = $this->useCaseBus->handle(new DeletePlanCommand($id));
        $failure = $this->domainFailure($result);
        if ($failure instanceof PlanNotFoundException) {
            throw $this->createNotFoundException();
        }
        if (null !== $failure) {
            $this->addFlash('error', $failure->getMessage());
        } else {
            /** @var array{name: string} $deleted */
            $deleted = $result->data();
            $this->addFlash('success', \sprintf('Plan %s deleted.', $deleted['name']));
        }

        return $this->redirectToRoute('admin_plans');
    }

    /** @return PlanData */
    private function plan(int $id): array
    {
        $result = $this->queryBus->handle(new GetPlanCommand($id));
        if (null !== $this->domainFailure($result)) {
            throw $this->createNotFoundException();
        }
        /** @var PlanData $plan */
        $plan = $result->data();

        return $plan;
    }

    /**
     * Sends the form's command through the bus. Gives the result's data, or null after putting
     * the refusal under the name (a name taken a moment ago, or a plan deleted meanwhile).
     *
     * @template T
     *
     * @param FormInterface<T> $form
     */
    private function submit(FormInterface $form, CreatePlanCommand|UpdatePlanCommand $command): mixed
    {
        $result = $this->useCaseBus->handle($command);
        $failure = $this->domainFailure($result);
        // Only reached when the form's own name check passed and the save still refused (a race)
        null === $failure || $form->get('name')->addError(new FormError($failure->getMessage()));

        return null === $failure ? $result->data() : null;
    }
}
