<?php

namespace App\Controller;

use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Entity\PersonalDataSheet;
use App\Repository\PersonalDataSheetRepository;
use App\Schools\Application\Service\SchoolTeacherManager;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\School;
use App\Schools\Presentation\Security\SchoolVoter;
use App\Service\PdsPdfRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A teacher's PDS as a PDF, for the school's file. Never writes the teacher's data. */
#[Route('/schools/{id}/teachers', requirements: ['id' => '\d+'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolTeacherPdsController extends AbstractController
{
    #[Route('/{teacher}/pds.pdf', name: 'school_teacher_pds', requirements: ['teacher' => '\d+'], methods: ['GET'])]
    public function pds(
        School $school,
        int $teacher,
        SchoolTeacherManager $teachers,
        SchoolYearRepositoryInterface $schoolYears,
        PersonalDataSheetRepository $sheets,
        PdsPdfRenderer $renderer,
        ActivityLoggerInterface $activityLogger,
        EntityManagerInterface $entityManager,
    ): Response {
        $schoolYear = $schoolYears->findCurrent() ?? throw $this->createNotFoundException('No current school year.');
        $user = $teachers->findTeacher($school, $schoolYear, $teacher)
            ?? throw $this->createNotFoundException('This teacher does not belong to the school this school year.');
        $sheet = $sheets->findOneBy(['user' => $user]) ?? new PersonalDataSheet($user);

        $activityLogger->log('Downloaded PDS of '.$user->getUsername());
        $entityManager->flush();

        return new Response($renderer->render($sheet), headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $renderer->filename($sheet)),
        ]);
    }
}
