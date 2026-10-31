<?php

namespace App\Controller;

use App\CardSlip\CardSlip;
use App\CardSlip\CardSlipBuilder;
use App\CardSlip\CardSlipCommentRecorder;
use App\CardSlip\CardSlipComments;
use App\CardSlip\CardSlipLine;
use App\CardSlip\CardSlipPdf;
use App\Entity\GradingPeriod;
use App\Entity\Section;
use App\Entity\User;
use App\Form\CardSlipCommentsData;
use App\Form\CardSlipCommentsFormType;
use App\Grading\GradeRules;
use App\Repository\GradeLockRepository;
use App\Repository\GradeOpeningRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\SectionRepository;
use App\Security\Voter\CardSlipVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The adviser's card slips for a term: the comments of each learner (saved, else suggested) and the slips as PDF.
 * Only the adviser of a section this school year gets here; the advisory is looked up on every request.
 */
#[IsGranted(User::ROLE_TEACHER)]
#[Route('/teacher/class/card-slips', requirements: ['period' => '\d+', 'enrollment' => '\d+'])]
class TeacherCardSlipController extends AbstractController
{
    public const NO_LEARNERS = 'No learners to print slips for.';

    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SectionRepository $sections,
        private readonly EntityManagerInterface $entityManager,
        private readonly CardSlipBuilder $builder,
        private readonly CardSlipCommentRecorder $recorder,
        private readonly CardSlipPdf $pdf,
        private readonly GradeLockRepository $locks,
        private readonly GradeOpeningRepository $openings,
        private readonly GradeRules $rules,
    ) {
    }

    #[Route('', name: 'teacher_class_card_slips', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] User $user, #[MapQueryParameter] ?int $period = null): Response
    {
        $section = $this->advisory($user);
        $this->denyAccessUnlessGranted(CardSlipVoter::EDIT, $section);
        $selected = null === $period ? $this->defaultPeriod($section) : $this->period($section, $period);

        $slips = null === $selected ? [] : $this->builder->forSection($section, $selected);
        $form = null;
        if ([] !== $slips) {
            $names = [];
            foreach ($slips as $slip) {
                $names[$slip->enrollmentId] = $slip->learnerName;
            }
            $form = $this->createForm(CardSlipCommentsFormType::class, CardSlipCommentsData::of($slips), ['names' => $names]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                /** @var CardSlipCommentsData $data */
                $data = $form->getData();
                $count = $this->recorder->save($section, $selected, $data->entries, $user);
                $this->addFlash('success', \sprintf('Comments saved for %d %s.', $count, 1 === $count ? 'learner' : 'learners'));

                return $this->redirectToRoute('teacher_class_card_slips', ['period' => $selected->getId()]);
            }
        }

        return $this->render('card_slip/index.html.twig', [
            'section' => $section,
            'periods' => $section->getSchoolYear()->getGradingPeriods(),
            'period' => $selected,
            'locked' => null !== $selected && null !== $this->locks->findFor($section->getSchool(), $selected),
            'rows' => array_map(static fn (CardSlip $slip) => ['slip' => $slip, 'labels' => self::labels($slip)], $slips),
            'groups' => CardSlipComments::GROUPS,
            'form' => $form,
        ]);
    }

    #[Route('/{period}/{enrollment}/reset', name: 'teacher_class_card_slip_reset', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"card-slip-reset-" ~ args["enrollment"]'), tokenKey: '_token')]
    public function reset(#[CurrentUser] User $user, int $period, int $enrollment): Response
    {
        $section = $this->advisory($user);
        $this->denyAccessUnlessGranted(CardSlipVoter::EDIT, $section);
        $period = $this->period($section, $period);

        $learner = null;
        foreach ($this->rules->sheet($section) as $row) {
            if ($row->editable && $row->enrollment->getId() === $enrollment) {
                $learner = $row;
            }
        }
        if (null === $learner) {
            throw $this->createNotFoundException('No such learner in your class.');
        }

        $this->recorder->reset($learner->enrollment, $period);
        $this->addFlash('success', \sprintf('%s’s comments are back to the suggestions.', $learner->name));

        return $this->redirectToRoute('teacher_class_card_slips', ['period' => $period->getId()]);
    }

    #[Route('/{period}.pdf', name: 'teacher_class_card_slips_pdf', methods: ['GET'])]
    public function pdfAll(#[CurrentUser] User $user, int $period): Response
    {
        $section = $this->advisory($user);
        $this->denyAccessUnlessGranted(CardSlipVoter::PRINT, $section);
        $period = $this->period($section, $period);

        $slips = $this->builder->forSection($section, $period);
        if ([] === $slips) {
            $this->addFlash('error', self::NO_LEARNERS);

            return $this->redirectToRoute('teacher_class_card_slips', ['period' => $period->getId()]);
        }

        return $this->pdfResponse($this->pdf->render($slips), CardSlipPdf::filename($section, $period));
    }

    #[Route('/{period}/{enrollment}.pdf', name: 'teacher_class_card_slip_pdf', methods: ['GET'])]
    public function pdfOne(#[CurrentUser] User $user, int $period, int $enrollment): Response
    {
        $section = $this->advisory($user);
        $this->denyAccessUnlessGranted(CardSlipVoter::PRINT, $section);
        $slip = $this->builder->forEnrollment($section, $this->period($section, $period), $enrollment)
            ?? throw $this->createNotFoundException('No such learner in your class.');

        return $this->pdfResponse($this->pdf->render([$slip]), CardSlipPdf::learnerFilename($slip));
    }

    private function advisory(User $user): Section
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $section = null === $schoolYear ? null : $this->sections->findAdvisory($user, $schoolYear);
        if (null === $section) {
            throw $this->createAccessDeniedException('You do not advise a section this school year.');
        }

        return $section;
    }

    private function period(Section $section, int $id): GradingPeriod
    {
        $period = $this->entityManager->find(GradingPeriod::class, $id);
        if (null === $period || $period->getSchoolYear()->getId() !== $section->getSchoolYear()->getId()) {
            throw $this->createNotFoundException('No such grading period.');
        }

        return $period;
    }

    /** The latest locked term, else the earliest open one, else the last; null without terms. */
    private function defaultPeriod(Section $section): ?GradingPeriod
    {
        $periods = $section->getSchoolYear()->getGradingPeriods()->getValues();
        usort($periods, static fn (GradingPeriod $a, GradingPeriod $b) => $a->getNumber() <=> $b->getNumber());

        $locked = array_filter($periods, fn (GradingPeriod $p) => null !== $this->locks->findFor($section->getSchool(), $p));
        if ([] !== $locked) {
            return end($locked);
        }
        $open = $this->openings->findOpenPeriodIds($section->getSchool(), $section->getSchoolYear());
        foreach ($periods as $p) {
            if (\in_array($p->getId(), $open, true)) {
                return $p;
            }
        }

        return end($periods) ?: null;
    }

    /** @return array<string, string> checkbox label by code; "needs improvement" names the learner's failing subjects */
    private static function labels(CardSlip $slip): array
    {
        $failing = array_values(array_map(
            static fn (CardSlipLine $line) => $line->label,
            array_filter($slip->lines, static fn (CardSlipLine $line) => null !== $line->grade && $line->grade < 75),
        ));
        $labels = [];
        foreach (CardSlipComments::GROUPS as $codes) {
            foreach ($codes as $code) {
                $labels[$code] = 'needs_improvement' === $code && [] === $failing
                    ? CardSlipComments::text($code, ['{subjects}'])
                    : CardSlipComments::text($code, $failing);
            }
        }

        return $labels;
    }

    private function pdfResponse(string $pdf, string $fileName): Response
    {
        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, preg_replace('/[^\x20-\x7E]/', '_', $fileName)),
        ]);
    }
}
