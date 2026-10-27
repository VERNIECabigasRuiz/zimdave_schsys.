<?php

namespace App\Controller;

use App\Entity\ClassPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Form\ClassPeriodFormType;
use App\Repository\ClassPeriodRepository;
use App\Repository\SchoolYearRepository;
use App\Security\Voter\SchoolVoter;
use App\Service\ClassPeriodManager;
use App\Service\InUseException;
use App\Service\PlanLimitException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** The school day (class periods and breaks) of this S.Y. — admin for any school, principal for their own. */
#[Route('/schools/{id}/class-periods', requirements: ['id' => '\d+'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolClassPeriodController extends AbstractController
{
    public function __construct(
        private readonly ClassPeriodRepository $periods,
        private readonly ClassPeriodManager $manager,
        private readonly SchoolYearRepository $schoolYears,
    ) {
    }

    #[Route('', name: 'school_class_periods', methods: ['GET'])]
    public function index(School $school): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $periods = $schoolYear ? $this->periods->findForSchool($school, $schoolYear) : [];

        return $this->render('school_class_periods/index.html.twig', [
            'school' => $school,
            'school_year' => $schoolYear,
            'layout' => $this->layout(),
            'periods' => $periods,
            'numbers' => $this->periods->numbering($periods),
            'refusal' => $this->manager->refusal($school),
        ]);
    }

    #[Route('/new', name: 'school_class_periods_new', methods: ['GET', 'POST'])]
    public function new(Request $request, School $school): Response
    {
        return $this->handleForm($request, $school, new ClassPeriod($school, $this->currentYear()));
    }

    #[Route('/{period}/edit', name: 'school_class_periods_edit', requirements: ['period' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, School $school, int $period): Response
    {
        return $this->handleForm($request, $school, $this->requirePeriod($school, $period));
    }

    #[Route('/{period}/delete', name: 'school_class_periods_delete', requirements: ['period' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"class-period-" ~ args["period"]'), tokenKey: '_token')]
    public function delete(School $school, int $period): Response
    {
        $period = $this->requirePeriod($school, $period);
        try {
            $label = $this->periods->label($period);
            $this->manager->delete($period);
            $this->addFlash('success', \sprintf('Deleted %s.', $label));
        } catch (PlanLimitException|InUseException $e) {
            $this->addFlash('error', $e->getMessage());
        }

        return $this->redirectToRoute('school_class_periods', ['id' => $school->getId()]);
    }

    private function handleForm(Request $request, School $school, ClassPeriod $period): Response
    {
        if (null !== $refusal = $this->manager->refusal($school)) {
            $this->addFlash('error', $refusal);

            return $this->redirectToRoute('school_class_periods', ['id' => $school->getId()]);
        }

        $form = $this->createForm(ClassPeriodFormType::class, $period);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $isNew = null === $period->getId();
            try {
                $this->manager->save($period);
                $this->addFlash('success', \sprintf($isNew ? 'Added %s.' : 'Saved %s.', $this->periods->label($period)));
            } catch (PlanLimitException|InUseException $e) {
                $this->addFlash('error', $e->getMessage());
            }

            return $this->redirectToRoute('school_class_periods', ['id' => $school->getId()]);
        }

        return $this->render('school_class_periods/form.html.twig', [
            'school' => $school,
            'school_year' => $period->getSchoolYear(),
            'layout' => $this->layout(),
            'form' => $form,
            'period' => $period,
        ], new Response(status: $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
    }

    /** A period this page manages: same school, current S.Y. */
    private function requirePeriod(School $school, int $id): ClassPeriod
    {
        $period = $this->periods->find($id);
        if (null === $period || $period->getSchool() !== $school || $period->getSchoolYear() !== $this->currentYear()) {
            throw $this->createNotFoundException('This class period is not part of the school day this school year.');
        }

        return $period;
    }

    private function currentYear(): SchoolYear
    {
        return $this->schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');
    }

    private function layout(): string
    {
        return $this->isGranted('ROLE_ADMIN') ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig';
    }
}
