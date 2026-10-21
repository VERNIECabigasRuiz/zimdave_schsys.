<?php

namespace App\Controller\Admin;

use App\Entity\Plan;
use App\Entity\User;
use App\Form\PlanFormType;
use App\Repository\PlanRepository;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin/plans')]
class PlanController extends AbstractController
{
    public function __construct(
        private readonly PlanRepository $plans,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityLogger $activityLogger,
    ) {
    }

    #[Route('', name: 'admin_plans', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/plans/index.html.twig', [
            'plans' => $this->plans->findAllOrdered(),
            'school_counts' => $this->plans->countSchoolsByPlan(),
        ]);
    }

    #[Route('/new', name: 'admin_plans_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $plan = new Plan();
        $form = $this->createForm(PlanFormType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($plan);
            $this->activityLogger->log('Created plan '.$plan->getName());
            $this->entityManager->flush();

            $this->addFlash('success', \sprintf('Plan %s created.', $plan->getName()));

            return $this->redirectToRoute('admin_plans');
        }

        return $this->render('admin/plans/form.html.twig', ['form' => $form, 'plan' => null]);
    }

    #[Route('/{id}/edit', name: 'admin_plans_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, Plan $plan): Response
    {
        $form = $this->createForm(PlanFormType::class, $plan);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->activityLogger->log('Updated plan '.$plan->getName());
            $this->entityManager->flush();

            $this->addFlash('success', \sprintf('Plan %s saved.', $plan->getName()));

            return $this->redirectToRoute('admin_plans');
        }

        return $this->render('admin/plans/form.html.twig', ['form' => $form, 'plan' => $plan]);
    }

    #[Route('/{id}/delete', name: 'admin_plans_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"plan-" ~ args["plan"].getId()'), tokenKey: '_token')]
    public function delete(Plan $plan): Response
    {
        $schools = $this->plans->countSchoolsByPlan()[$plan->getId()] ?? 0;
        if ($schools > 0) {
            $this->addFlash('error', \sprintf('%s is used by %d school(s) and cannot be deleted.', $plan->getName(), $schools));

            return $this->redirectToRoute('admin_plans');
        }

        $this->entityManager->remove($plan);
        $this->activityLogger->log('Deleted plan '.$plan->getName());
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('Plan %s deleted.', $plan->getName()));

        return $this->redirectToRoute('admin_plans');
    }
}
