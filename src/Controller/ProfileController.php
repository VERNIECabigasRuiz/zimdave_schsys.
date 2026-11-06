<?php

namespace App\Controller;

use App\Entity\PersonalDataSheet;
use App\Access\Domain\Model\User;
use App\Form\FamilyBackgroundFormType;
use App\Form\OtherInfoFormType;
use App\Form\PdsListFormType;
use App\Form\PdsListSections;
use App\Form\PersonalInfoFormType;
use App\Form\QuestionsFormType;
use App\Form\ReferencesFormType;
use App\Entity\PdsReference;
use App\Repository\PersonalDataSheetRepository;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Service\PdsPdfRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The signed-in user's own profile and Personal Data Sheet (CS Form 212).
 * Always works on the current user, so there are no ids in the URLs.
 */
#[IsGranted(new Expression("is_granted('ROLE_PRINCIPAL') or is_granted('ROLE_TEACHER')"))]
#[Route('/profile')]
class ProfileController extends AbstractController
{
    public function __construct(
        private readonly PersonalDataSheetRepository $sheets,
        private readonly EntityManagerInterface $entityManager,
        private readonly ActivityLoggerInterface $activityLogger,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[Route('', name: 'profile_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): Response
    {
        $sheet = $this->sheets->findOrCreateFor($user);
        $this->entityManager->flush();

        return $this->render('profile/show.html.twig', ['sheet' => $sheet]);
    }

    #[Route('/personal', name: 'profile_personal', methods: ['GET', 'POST'])]
    public function personal(Request $request, #[CurrentUser] User $user): Response
    {
        $sheet = $this->sheets->findOrCreateFor($user);
        $form = $this->createForm(PersonalInfoFormType::class, $sheet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $sheet->syncPermanentAddress();
            $this->save($sheet, $user);
            $this->addFlash('success', 'Personal information saved.');

            return $this->redirectToRoute('profile_personal');
        }

        return $this->render('profile/personal.html.twig', ['sheet' => $sheet, 'form' => $form]);
    }

    #[Route('/family', name: 'profile_family', methods: ['GET', 'POST'])]
    public function family(Request $request, #[CurrentUser] User $user): Response
    {
        $sheet = $this->sheets->findOrCreateFor($user);
        $form = $this->createForm(FamilyBackgroundFormType::class, $sheet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $sheet->renumberChildren();
            $this->save($sheet, $user);
            $this->addFlash('success', 'Family background saved.');

            return $this->redirectToRoute('profile_family');
        }

        return $this->render('profile/family.html.twig', ['sheet' => $sheet, 'form' => $form]);
    }

    #[Route('/education', name: 'profile_education', methods: ['GET', 'POST'])]
    public function education(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->listSection($request, $user, 'education');
    }

    #[Route('/eligibility', name: 'profile_eligibility', methods: ['GET', 'POST'])]
    public function eligibility(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->listSection($request, $user, 'eligibility');
    }

    #[Route('/work', name: 'profile_work', methods: ['GET', 'POST'])]
    public function work(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->listSection($request, $user, 'work');
    }

    #[Route('/training', name: 'profile_training', methods: ['GET', 'POST'])]
    public function training(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->listSection($request, $user, 'training');
    }

    #[Route('/voluntary', name: 'profile_voluntary', methods: ['GET', 'POST'])]
    public function voluntary(Request $request, #[CurrentUser] User $user): Response
    {
        return $this->listSection($request, $user, 'voluntary');
    }

    #[Route('/other', name: 'profile_other', methods: ['GET', 'POST'])]
    public function other(Request $request, #[CurrentUser] User $user): Response
    {
        $sheet = $this->sheets->findOrCreateFor($user);
        $form = $this->createForm(OtherInfoFormType::class, $sheet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $sheet->renumberLists();
            $this->save($sheet, $user);
            $this->addFlash('success', 'Other information saved.');

            return $this->redirectToRoute('profile_other');
        }

        return $this->render('profile/other.html.twig', ['sheet' => $sheet, 'form' => $form, 'lists' => OtherInfoFormType::LISTS]);
    }

    #[Route('/questions', name: 'profile_questions', methods: ['GET', 'POST'])]
    public function questions(Request $request, #[CurrentUser] User $user): Response
    {
        $sheet = $this->sheets->findOrCreateFor($user);
        $form = $this->createForm(QuestionsFormType::class, $sheet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $sheet->clearUnusedAnswerDetails();
            $this->save($sheet, $user);
            $this->addFlash('success', 'Answers to questions 34–40 saved.');

            return $this->redirectToRoute('profile_questions');
        }

        return $this->render('profile/questions.html.twig', [
            'sheet' => $sheet,
            'form' => $form,
            'questions' => PersonalDataSheet::QUESTION_DETAILS,
            'wording' => QuestionsFormType::WORDING,
            'item_40' => QuestionsFormType::ITEM_40,
        ]);
    }

    #[Route('/references', name: 'profile_references', methods: ['GET', 'POST'])]
    public function references(Request $request, #[CurrentUser] User $user): Response
    {
        $sheet = $this->sheets->findOrCreateFor($user);
        // The form always shows the three rows of item 41; empty ones are dropped on save
        while ($sheet->getReferences()->count() < 3) {
            $sheet->addReference(new PdsReference());
        }
        $form = $this->createForm(ReferencesFormType::class, $sheet);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $sheet->removeEmptyReferences();
            $sheet->renumberLists();
            $this->save($sheet, $user);
            $this->addFlash('success', 'References and government ID saved.');

            return $this->redirectToRoute('profile_references');
        }

        return $this->render('profile/references.html.twig', [
            'sheet' => $sheet,
            'form' => $form,
            'gov_id_types' => ReferencesFormType::GOV_ID_TYPES,
        ]);
    }

    /** Shows and saves one PDS list tab (III–VII); see PdsListSections for each tab's wording. */
    private function listSection(Request $request, User $user, string $key): Response
    {
        $section = PdsListSections::get($key);
        $sheet = $this->sheets->findOrCreateFor($user);
        $form = $this->formFactory->createNamed($key.'_section_form', PdsListFormType::class, $sheet, [
            'field' => $section['field'],
            'entry_type' => $section['entry_type'],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $sheet->renumberLists();
            $this->save($sheet, $user);
            $this->addFlash('success', $section['saved']);

            return $this->redirectToRoute('profile_'.$key);
        }

        return $this->render('profile/list_section.html.twig', [
            'sheet' => $sheet,
            'form' => $form,
            'section' => $section,
            'tab' => $key,
        ]);
    }

    #[Route('/pds.pdf', name: 'profile_pdf', methods: ['GET'])]
    public function pdf(#[CurrentUser] User $user, PdsPdfRenderer $renderer): Response
    {
        $sheet = $this->sheets->findOrCreateFor($user);
        $this->entityManager->flush();

        return new Response($renderer->render($sheet), headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $renderer->filename($sheet)),
        ]);
    }

    private function save(PersonalDataSheet $sheet, User $user): void
    {
        $sheet->touch();
        if (null !== $name = $sheet->displayName()) {
            $user->setFullName(mb_substr($name, 0, 120));
        }
        $this->activityLogger->log('Updated own profile');
        $this->entityManager->flush();
    }
}
