<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Application\Query\Command\GetSchoolProfileCommand;
use App\Schools\Application\UseCase\Command\UpdateSchoolProfileCommand;
use App\Schools\Presentation\Form\SchoolProfileFormType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @phpstan-import-type SchoolData from \App\Schools\Application\Transformer\SchoolTransformer
 * @phpstan-import-type SchoolYearData from \App\Schools\Application\Transformer\SchoolYearTransformer
 */
#[IsGranted(User::ROLE_PRINCIPAL)]
class SchoolProfileController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('/principal/school', name: 'principal_school', methods: ['GET', 'POST'])]
    public function edit(Request $request, #[CurrentUser] User $user): Response
    {
        $userId = (int) $user->getId();
        $result = $this->queryBus->handle(new GetSchoolProfileCommand($userId));
        $this->domainFailure($result);
        /** @var array{school: ?SchoolData, schoolYear: ?SchoolYearData} $profile */
        $profile = $result->data();
        $school = $profile['school'];
        if (null === $school) {
            return $this->render('principal/not_assigned.html.twig', [
                'school_year' => $profile['schoolYear'],
                'not_assigned' => true,
            ]);
        }

        $command = UpdateSchoolProfileCommand::fromSchool($userId, $school);
        $form = $this->createForm(SchoolProfileFormType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $command->logoFile = $this->uploadedPath($form->get('logo')->getData());
            $command->idSignatureFile = $this->uploadedPath($form->get('idSignature')->getData());
            $failure = $this->domainFailure($this->useCaseBus->handle($command));
            if (null === $failure) {
                $this->addFlash('success', 'School profile saved.');

                return $this->redirectToRoute('principal_school');
            }
            $form->get('logo')->addError(new FormError($failure->getMessage()));
        }

        return $this->render('@Schools/principal/school_profile.html.twig', [
            'school' => $school,
            'form' => $form,
        ]);
    }

    private function uploadedPath(mixed $file): ?string
    {
        return $file instanceof UploadedFile ? $file->getPathname() : null;
    }
}
