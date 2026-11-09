<?php

declare(strict_types=1);

namespace App\Staff\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Staff\Application\Query\Command\GetPdsPdfCommand;
use App\Staff\Application\Query\Command\GetPersonalDataSheetCommand;
use App\Staff\Application\UseCase\Command\OpenPersonalDataSheetCommand;
use App\Staff\Application\UseCase\Command\PdsTrainingRow;
use App\Staff\Application\UseCase\Command\SaveFamilyBackgroundCommand;
use App\Staff\Application\UseCase\Command\SaveOtherInfoCommand;
use App\Staff\Application\UseCase\Command\SavePdsListCommand;
use App\Staff\Application\UseCase\Command\SavePersonalInfoCommand;
use App\Staff\Application\UseCase\Command\SaveQuestionsCommand;
use App\Staff\Application\UseCase\Command\SaveReferencesCommand;
use App\Staff\Domain\Model\PersonalDataSheet;
use App\Staff\Presentation\Form\FamilyBackgroundFormType;
use App\Staff\Presentation\Form\OtherInfoFormType;
use App\Staff\Presentation\Form\PdsListFormType;
use App\Staff\Presentation\Form\PdsListSections;
use App\Staff\Presentation\Form\PersonalInfoFormType;
use App\Staff\Presentation\Form\QuestionsFormType;
use App\Staff\Presentation\Form\ReferencesFormType;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
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
class ProfileController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
        private readonly FormFactoryInterface $formFactory,
    ) {
    }

    #[Route('', name: 'profile_show', methods: ['GET'])]
    public function show(#[CurrentUser] User $user): Response
    {
        $this->send(new OpenPersonalDataSheetCommand((int) $user->getId()));

        return $this->render('@Staff/profile/show.html.twig', ['sheet' => $this->sheet($user)]);
    }

    #[Route('/personal', name: 'profile_personal', methods: ['GET', 'POST'])]
    public function personal(Request $request, #[CurrentUser] User $user): Response
    {
        $sheet = $this->sheet($user);
        $form = $this->createForm(
            PersonalInfoFormType::class,
            SavePersonalInfoCommand::fromSheet($sheet, (int) $user->getId()),
        );
        if (null !== $saved = $this->save($request, $form, 'Personal information saved.', 'profile_personal')) {
            return $saved;
        }

        return $this->render('@Staff/profile/personal.html.twig', ['sheet' => $sheet, 'form' => $form]);
    }

    #[Route('/family', name: 'profile_family', methods: ['GET', 'POST'])]
    public function family(Request $request, #[CurrentUser] User $user): Response
    {
        $sheet = $this->sheet($user);
        $form = $this->createForm(
            FamilyBackgroundFormType::class,
            SaveFamilyBackgroundCommand::fromSheet($sheet, (int) $user->getId()),
        );
        if (null !== $saved = $this->save($request, $form, 'Family background saved.', 'profile_family')) {
            return $saved;
        }

        return $this->render('@Staff/profile/family.html.twig', ['sheet' => $sheet, 'form' => $form]);
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
        $sheet = $this->sheet($user);
        $form = $this->createForm(
            OtherInfoFormType::class,
            SaveOtherInfoCommand::fromSheet($sheet, (int) $user->getId()),
        );
        if (null !== $saved = $this->save($request, $form, 'Other information saved.', 'profile_other')) {
            return $saved;
        }

        return $this->render(
            '@Staff/profile/other.html.twig',
            ['sheet' => $sheet, 'form' => $form, 'lists' => OtherInfoFormType::LISTS],
        );
    }

    #[Route('/questions', name: 'profile_questions', methods: ['GET', 'POST'])]
    public function questions(Request $request, #[CurrentUser] User $user): Response
    {
        $sheet = $this->sheet($user);
        $form = $this->createForm(
            QuestionsFormType::class,
            SaveQuestionsCommand::fromSheet($sheet, (int) $user->getId()),
        );
        if (null !== $saved = $this->save($request, $form, 'Answers to questions 34–40 saved.', 'profile_questions')) {
            return $saved;
        }

        return $this->render('@Staff/profile/questions.html.twig', [
            'sheet' => $sheet,
            'form' => $form,
            'questions' => PersonalDataSheet::QUESTION_DETAILS,
            'wording' => QuestionsFormType::WORDING,
            'item_40' => QuestionsFormType::ITEM_40,
        ]);
    }

    /** The form always shows the three rows of item 41; empty ones are dropped on save. */
    #[Route('/references', name: 'profile_references', methods: ['GET', 'POST'])]
    public function references(Request $request, #[CurrentUser] User $user): Response
    {
        $sheet = $this->sheet($user);
        $command = SaveReferencesCommand::fromSheet($sheet, (int) $user->getId());
        $form = $this->createForm(ReferencesFormType::class, $command);
        if (
            null !== $saved = $this->save(
                $request,
                $form,
                'References and government ID saved.',
                'profile_references',
            )
        ) {
            return $saved;
        }

        return $this->render('@Staff/profile/references.html.twig', [
            'sheet' => $sheet,
            'form' => $form,
            'gov_id_types' => ReferencesFormType::GOV_ID_TYPES,
        ]);
    }

    /** Shows and saves one PDS list tab (III–VII); see PdsListSections for each tab's wording. */
    private function listSection(Request $request, User $user, string $key): Response
    {
        $section = PdsListSections::get($key);
        $sheet = $this->sheet($user);
        $command = SavePdsListCommand::fromSheet($sheet, (int) $user->getId(), $key);
        $form = $this->formFactory->createNamed($key . '_section_form', PdsListFormType::class, $command, [
            'field' => $section['field'],
            'entry_type' => $section['entry_type'],
        ]);
        if (null !== $saved = $this->save($request, $form, $section['saved'], 'profile_' . $key)) {
            return $saved;
        }

        return $this->render('@Staff/profile/list_section.html.twig', [
            'sheet' => $sheet,
            'form' => $form,
            'section' => $section,
            'tab' => $key,
            'training_hours' => 'training' === $key ? self::trainingHours($command) : null,
        ]);
    }

    #[Route('/pds.pdf', name: 'profile_pdf', methods: ['GET'])]
    public function pdf(#[CurrentUser] User $user): Response
    {
        $this->send(new OpenPersonalDataSheetCommand((int) $user->getId()));
        $file = $this->ask(new GetPdsPdfCommand((int) $user->getId()));
        \assert(\is_string($file['content']) && \is_string($file['fileName']));

        return new Response($file['content'], headers: [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $file['fileName'],
            ),
        ]);
    }

    /** The total hours of the training rows on the form, or null when there are none. */
    private static function trainingHours(SavePdsListCommand $command): ?float
    {
        if ([] === $command->trainings) {
            return null;
        }

        return array_sum(array_map(static fn (PdsTrainingRow $t) => (float) $t->hours, $command->trainings));
    }

    private function sheet(User $user): PersonalDataSheet
    {
        $sheet = $this->ask(new GetPersonalDataSheetCommand((int) $user->getId()))['sheet'];
        \assert($sheet instanceof PersonalDataSheet);

        return $sheet;
    }

    /**
     * Saves a valid submitted tab and goes back to it; null when the form is to be shown.
     *
     * @template T
     *
     * @param FormInterface<T> $form
     */
    private function save(Request $request, FormInterface $form, string $saved, string $route): ?Response
    {
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            return null;
        }
        $command = $form->getData();
        \assert($command instanceof UseCase);
        $this->send($command);
        $this->addFlash('success', $saved);

        return $this->redirectToRoute($route);
    }

    private function send(UseCase $command): void
    {
        $this->refusal($this->useCaseBus->handle($command));
    }

    /** @return array<string, mixed> */
    private function ask(GetPersonalDataSheetCommand|GetPdsPdfCommand $query): array
    {
        $result = $this->queryBus->handle($query);
        $this->refusal($result);
        $data = $result->data();
        \assert(\is_array($data));

        /** @var array<string, mixed> $data */
        return $data;
    }

    /** The signed-in account is always found, so any refusal is a 404. */
    private function refusal(Result $result): void
    {
        if (null !== $failure = $this->domainFailure($result)) {
            throw $this->createNotFoundException($failure->getMessage());
        }
    }
}
