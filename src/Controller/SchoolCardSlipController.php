<?php

namespace App\Controller;

use App\CardSlip\CardSlipBuilder;
use App\CardSlip\CardSlipPdf;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Presentation\Security\SchoolVoter;
use App\Security\Voter\CardSlipVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A section's card slips for a term as PDF, from the school's grade pages — admin for any school, principal for their own. */
#[Route('/schools/{id}', requirements: ['id' => '\d+', 'section' => '\d+', 'subject' => '\d+', 'period' => '\d+'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolCardSlipController extends AbstractController
{
    public function __construct(private readonly SectionRepositoryInterface $sections)
    {
    }

    /** The section's card slips for a term as PDF, read-only: what the adviser saved, else the suggestions. */
    #[Route('/sections/{section}/card-slips/{period}.pdf', name: 'school_section_card_slips_pdf', methods: ['GET'])]
    public function cardSlips(School $school, int $section, int $period, CardSlipBuilder $builder, CardSlipPdf $pdf): Response
    {
        $section = $this->sections->findById($section);
        if (null === $section || $section->getSchool()->getId() !== $school->getId()) {
            throw $this->createNotFoundException();
        }
        $this->denyAccessUnlessGranted(CardSlipVoter::PRINT, $section);
        $term = $section->getSchoolYear()->getGradingPeriods()->findFirst(static fn (int $i, GradingPeriod $p) => $p->getId() === $period)
            ?? throw $this->createNotFoundException('No such grading period.');

        $slips = $builder->forSection($section, $term);
        if ([] === $slips) {
            $this->addFlash('error', 'No learners to print slips for.');

            return $this->redirectToRoute('school_grades_section', ['id' => $school->getId(), 'section' => $section->getId(), 'period' => $term->getId()]);
        }

        $fileName = CardSlipPdf::filename($section, $term);

        return new Response($pdf->render($slips), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, (string) preg_replace('/[^\x20-\x7E]/', '_', $fileName)),
        ]);
    }
}
