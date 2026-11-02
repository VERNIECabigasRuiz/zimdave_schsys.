<?php

namespace App\Controller\Admin;

use App\Entity\GradingPeriod;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Form\SchoolYearFormType;
use App\Repository\GradeLockRepository;
use App\Repository\GradeOpeningRepository;
use App\Repository\GradeRepository;
use App\Repository\SchoolRepository;
use App\Repository\SchoolYearRepository;
use App\Service\ActivityLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin/school-years')]
class SchoolYearController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityLogger $activityLogger,
        private readonly GradeRepository $grades,
        private readonly GradeLockRepository $locks,
        private readonly GradeOpeningRepository $openings,
        private readonly SchoolRepository $schools,
    ) {
    }

    #[Route('', name: 'admin_school_years', methods: ['GET'])]
    public function index(): Response
    {
        $schoolYears = $this->schoolYears->findAllNewestFirst();
        $openCounts = [];
        foreach ($schoolYears as $schoolYear) {
            foreach ($schoolYear->getGradingPeriods() as $period) {
                $openCounts[$period->getId()] = $this->openings->countSchoolsOpen($period);
            }
        }

        return $this->render('admin/school_years/index.html.twig', [
            'school_years' => $schoolYears,
            'open_counts' => $openCounts,
            'school_count' => $this->schools->count([]),
        ]);
    }

    #[Route('/new', name: 'admin_school_year_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $schoolYear = new SchoolYear();
        $this->suggestNextYear($schoolYear);

        $form = $this->createForm(SchoolYearFormType::class, $schoolYear);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // The first school year ever created becomes the current one
            if (null === $this->schoolYears->findCurrent()) {
                $schoolYear->setCurrent(true);
            }
            $schoolYear->rebuildGradingPeriods();
            $this->entityManager->persist($schoolYear);
            $this->activityLogger->log('Created school year '.$schoolYear->getName());
            $this->entityManager->flush();

            $this->addFlash('success', \sprintf(
                'School year %s created with %d %ss. Use Edit to set the exact dates of each %s.',
                $schoolYear->getName(),
                $schoolYear->getGradingSystem()->periodCount(),
                strtolower($schoolYear->getGradingSystem()->periodName()),
                strtolower($schoolYear->getGradingSystem()->periodName()),
            ));

            return $this->redirectToRoute('admin_school_years');
        }

        return $this->render('admin/school_years/form.html.twig', [
            'form' => $form,
            'school_year' => null,
        ]);
    }

    #[Route('/{id}/edit', name: 'admin_school_year_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    public function edit(Request $request, SchoolYear $schoolYear): Response
    {
        $originalSystem = $schoolYear->getGradingSystem();
        $hadOpenPeriod = $this->openings->existsForSchoolYear($schoolYear);
        $periodsBefore = $schoolYear->getGradingPeriods()->toArray();
        $form = $this->createForm(SchoolYearFormType::class, $schoolYear, ['with_periods' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $schoolYear->getGradingSystem() !== $originalSystem) {
            if ($hadOpenPeriod) {
                $form->get('gradingSystem')->addError(new FormError('Close grading in every school before switching between terms and quarters.'));
            } elseif (!$schoolYear->hasMatchingGradingPeriods()) {
                // The page normally redraws the period rows for the new system; without that, split evenly
                $schoolYear->rebuildGradingPeriods();
            }
        }

        // Deleting a period deletes its grades and locks in every school, so a graded period stays
        if ($form->isSubmitted() && $form->isValid() && null !== $graded = $this->firstGradedPeriodRemoved($schoolYear, $periodsBefore)) {
            $form->get('gradingSystem')->addError(new FormError(\sprintf(
                '%s has grades. Switching to %ss would delete them.',
                $originalSystem->periodName().' '.$graded->getNumber(), // its label under the system it belongs to
                strtolower($schoolYear->getGradingSystem()->periodName()),
            )));
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $this->activityLogger->log('Updated school year '.$schoolYear->getName());
            $this->entityManager->flush();

            $this->addFlash('success', \sprintf('School year %s saved.', $schoolYear->getName()));

            return $this->redirectToRoute('admin_school_years');
        }

        return $this->render('admin/school_years/form.html.twig', [
            'form' => $form,
            'school_year' => $schoolYear,
        ]);
    }

    #[Route('/{id}/make-current', name: 'admin_school_year_make_current', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"school-year-" ~ args["schoolYear"].getId()'), tokenKey: '_token')]
    public function makeCurrent(SchoolYear $schoolYear): Response
    {
        $this->schoolYears->makeCurrent($schoolYear);
        $this->activityLogger->log('Set current school year to '.$schoolYear->getName());
        $this->entityManager->flush();

        $this->addFlash('success', \sprintf('%s is now the current school year.', $schoolYear->getName()));

        return $this->redirectToRoute('admin_school_years');
    }

    /**
     * Pre-fills the form with the school year after the latest one (June to April).
     */
    private function suggestNextYear(SchoolYear $schoolYear): void
    {
        $latest = $this->schoolYears->findAllNewestFirst()[0] ?? null;
        $startYear = $latest?->getEndDate()?->format('Y') ?? (new \DateTimeImmutable())->format('Y');
        $startYear = (int) $startYear;

        $schoolYear
            ->setName(\sprintf('%d-%d', $startYear, $startYear + 1))
            ->setStartDate(new \DateTimeImmutable(\sprintf('%d-06-01', $startYear)))
            ->setEndDate(new \DateTimeImmutable(\sprintf('%d-04-30', $startYear + 1)));
    }

    /**
     * The first period, by number, that the edit takes away although grades were entered or locked in it.
     *
     * @param list<GradingPeriod> $before the periods before the edit
     */
    private function firstGradedPeriodRemoved(SchoolYear $schoolYear, array $before): ?GradingPeriod
    {
        $removed = array_filter($before, static fn (GradingPeriod $period) => !$schoolYear->getGradingPeriods()->contains($period));
        usort($removed, static fn (GradingPeriod $a, GradingPeriod $b) => $a->getNumber() <=> $b->getNumber());
        foreach ($removed as $period) {
            if ($this->grades->countForPeriod($period) > 0 || $this->locks->count(['gradingPeriod' => $period]) > 0) {
                return $period;
            }
        }

        return null;
    }
}
