<?php

declare(strict_types=1);

namespace App\Staff\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;
use App\Staff\Application\UseCase\Command\DownloadTeacherPdsCommand;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A teacher's PDS as a PDF, for the school's file. Never writes the teacher's data. */
#[Route('/schools/{id}/teachers', requirements: ['id' => '\d+'])]
#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]
class SchoolTeacherPdsController extends BusController
{
    #[Route('/{teacher}/pds.pdf', name: 'school_teacher_pds', requirements: ['teacher' => '\d+'], methods: ['GET'])]
    public function pds(
        School $school,
        int $teacher,
        #[CurrentUser] User $user,
        UseCaseBusInterface $useCaseBus,
    ): Response {
        $result = $useCaseBus->handle(
            new DownloadTeacherPdsCommand((int) $user->getId(), (int) $school->getId(), $teacher),
        );
        if (null !== $failure = $this->domainFailure($result)) {
            throw $this->createNotFoundException($failure->getMessage());
        }
        $file = $result->data();
        \assert(\is_array($file) && \is_string($file['content']) && \is_string($file['fileName']));

        return new Response($file['content'], headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $file['fileName'],
            ),
        ]);
    }
}
