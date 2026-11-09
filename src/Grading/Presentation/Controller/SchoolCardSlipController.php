<?php

declare(strict_types=1);

namespace App\Grading\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Grading\Application\Query\Command\GetCardSlipPdfCommand;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A section's card slips as PDF from the school's grade pages — admin for any school, principal for their own. */
#[Route('/schools/{id}', requirements: ['id' => '\d+', 'section' => '\d+', 'subject' => '\d+', 'period' => '\d+'])]
#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]
class SchoolCardSlipController extends AbstractGradingController
{
    /** The section's card slips for a term as PDF, read-only: what the adviser saved, else the suggestions. */
    #[Route('/sections/{section}/card-slips/{period}.pdf', name: 'school_section_card_slips_pdf', methods: ['GET'])]
    public function cardSlips(School $school, int $section, int $period, #[CurrentUser] User $user): Response
    {
        $file = $this->ask(new GetCardSlipPdfCommand(
            (int) $user->getId(),
            (int) $school->getId(),
            $section,
            $period,
            null,
        ));
        if (!\is_string($file['content'])) {
            $this->addFlash('error', TeacherCardSlipController::NO_LEARNERS);

            return $this->redirectToRoute(
                'school_grades_section',
                ['id' => $school->getId(), 'section' => $section, 'period' => $period],
            );
        }

        return $this->download($file);
    }
}
