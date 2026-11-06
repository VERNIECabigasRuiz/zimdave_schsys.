<?php

namespace App\Controller;

use App\Entity\School;
use App\Access\Domain\Model\User;
use App\Form\StaffIdDetailsFormType;
use App\Repository\DivisionRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\StaffIdDetailsRepository;
use App\StaffId\StaffIdCardBuilder;
use App\StaffId\StaffIdPdf;
use App\StaffId\StaffIdSize;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A teacher or principal prints their own ID; only their own, and only while they have a school this S.Y. */
#[IsGranted('IS_AUTHENTICATED')]
#[Route('/my-id')]
class MyIdController extends AbstractController
{
    public function __construct(
        private readonly SchoolYearRepository $schoolYears,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly StaffIdCardBuilder $builder,
        private readonly StaffIdDetailsRepository $details,
        private readonly DivisionRepository $divisions,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('', name: 'my_id', methods: ['GET', 'POST'])]
    public function show(Request $request, #[CurrentUser] User $user): Response
    {
        [$school, $role] = $this->school($user);
        $details = $this->details->findOrNew($user);
        $form = $this->createForm(StaffIdDetailsFormType::class, $details);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($details);
            $this->entityManager->flush();
            $this->addFlash('success', 'ID details saved.');

            return $this->redirectToRoute('my_id');
        }

        $card = $this->builder->build($user, $school);

        return $this->render('staff_id/my_id.html.twig', [
            'card' => $card,
            'form' => $form,
            'missing' => $this->builder->missing($user),
            'can_print' => $this->builder->canPrint($user),
            'is_principal' => User::ROLE_PRINCIPAL === $role,
            'division' => User::ROLE_PRINCIPAL === $role ? $this->divisions->findForSchool($school) : null,
            'school' => $school,
        ]);
    }

    #[Route('/{size}.pdf', name: 'my_id_pdf', requirements: ['size' => 'big|small'], methods: ['GET'])]
    public function pdf(string $size, #[CurrentUser] User $user, StaffIdPdf $pdf): Response
    {
        [$school] = $this->school($user);
        if (!$this->builder->canPrint($user)) {
            $this->addFlash('error', 'Add your photo and a current (Present) service record row before printing your ID.');

            return $this->redirectToRoute('my_id');
        }

        $size = StaffIdSize::from($size);
        $card = $this->builder->build($user, $school);

        return new Response($pdf->render($card, $size), Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, StaffIdPdf::filename($card, $size), StaffIdPdf::asciiFilename($card, $size)),
        ]);
    }

    /** @return array{0: School, 1: string} the person's school this S.Y. and their role there */
    private function school(User $user): array
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $assignment = null === $schoolYear ? null : $this->assignments->findForUser($user, $schoolYear);
        if (null === $assignment || !\in_array($assignment->getRole(), [User::ROLE_TEACHER, User::ROLE_PRINCIPAL], true)) {
            throw $this->createAccessDeniedException('Only teachers and principals with a school this school year have an ID here.');
        }

        return [$assignment->getSchool(), $assignment->getRole()];
    }
}
