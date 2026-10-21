<?php

namespace App\Controller\Principal;

use App\Entity\User;
use App\Form\SchoolProfileFormType;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use App\Security\Voter\SchoolVoter;
use App\Service\ActivityLogger;
use App\Service\ImageProcessingException;
use App\Service\ImageProcessor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted(User::ROLE_PRINCIPAL)]
class SchoolProfileController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly ImageProcessor $images,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityLogger $activityLogger,
    ) {
    }

    #[Route('/principal/school', name: 'principal_school', methods: ['GET', 'POST'])]
    public function edit(Request $request, #[CurrentUser] User $user): Response
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $school = $schoolYear ? $this->assignments->findSchoolOf($user, $schoolYear, User::ROLE_PRINCIPAL) : null;
        if (null === $school) {
            return $this->render('principal/not_assigned.html.twig', ['school_year' => $schoolYear]);
        }
        $this->denyAccessUnlessGranted(SchoolVoter::MANAGE, $school);

        $form = $this->createForm(SchoolProfileFormType::class, $school);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $logo = $form->get('logo')->getData();
            try {
                if ($logo instanceof UploadedFile) {
                    $school->setLogoPath($this->images->storeLogo($logo, $school->getLogoPath()));
                }
                $this->activityLogger->log('Updated school profile '.$school->getName());
                $this->entityManager->flush();
                $this->addFlash('success', 'School profile saved.');

                return $this->redirectToRoute('principal_school');
            } catch (ImageProcessingException $e) {
                $form->get('logo')->addError(new FormError($e->getMessage()));
            }
        }

        return $this->render('principal/school_profile.html.twig', [
            'school' => $school,
            'form' => $form,
        ]);
    }
}
