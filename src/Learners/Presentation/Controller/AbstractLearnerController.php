<?php

declare(strict_types=1);

namespace App\Learners\Presentation\Controller;

use App\Access\Domain\Exception\AccountNotFoundException;
use App\Classes\Application\Api\SectionDirectoryInterface;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Core\Presentation\Form\ImageUploadFormType;
use App\Learners\Application\Query\Command\GetClassListCommand;
use App\Learners\Application\Query\Command\GetEnrollmentRecordCommand;
use App\Learners\Application\Query\Command\GetEnrollPageCommand;
use App\Learners\Application\Query\Command\GetLearnerCommand;
use App\Learners\Application\Query\Command\GetLearnerFormCommand;
use App\Learners\Application\Query\Command\ListLearnersCommand;
use App\Learners\Application\Service\LearnerEnrollment;
use App\Learners\Application\Service\LookupKind;
use App\Learners\Application\UseCase\Command\EnrollLearnerCommand;
use App\Learners\Application\UseCase\Command\LearnerDetails;
use App\Learners\Application\UseCase\Command\MoveLearnerCommand;
use App\Learners\Application\UseCase\Command\RequestTransferCommand;
use App\Learners\Application\UseCase\Command\ResetLearnerPasswordCommand;
use App\Learners\Application\UseCase\Command\TransferLearnerCommand;
use App\Learners\Application\UseCase\Command\UnenrollLearnerCommand;
use App\Learners\Application\UseCase\Command\UpdateEnrollmentRecordCommand;
use App\Learners\Application\UseCase\Command\UpdateLearnerCommand;
use App\Learners\Application\UseCase\Command\UpdateLearnerPhotoCommand;
use App\Learners\Application\UseCase\Handler\TransferLearnerHandler;
use App\Learners\Domain\Exception\LearnerNotFoundException;
use App\Learners\Domain\Exception\LearnersAccessDeniedException;
use App\Learners\Domain\Exception\TransferNotFoundException;
use App\Learners\Presentation\Form\EnrollFormType;
use App\Learners\Presentation\Form\EnrollmentRecordFormType;
use App\Learners\Presentation\Form\LearnerFormType;
use App\Learners\Presentation\Form\TransferRequestFormType;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Learners pages, shared by the school pages (admin, principal) and the adviser's class pages.
 * Subclasses decide the page's scope and routes; every action checks the learner through the bus.
 *
 * @phpstan-import-type PageData from \App\Learners\Application\Transformer\LearnerPageTransformer
 * @phpstan-import-type SectionData from \App\Learners\Application\Transformer\EnrollmentTransformer
 * @phpstan-import-type SchoolData from \App\Learners\Application\Transformer\EnrollmentTransformer
 * @phpstan-import-type LearnerData from \App\Learners\Application\Transformer\LearnerTransformer
 * @phpstan-import-type EnrollmentData from \App\Learners\Application\Transformer\EnrollmentTransformer
 */
abstract class AbstractLearnerController extends BusController
{
    public function __construct(
        protected readonly UseCaseBusInterface $useCaseBus,
        protected readonly QueryBusInterface $queryBus,
        private readonly SectionDirectoryInterface $sections,
    ) {
    }

    /** Every learner enrolled at the school this year, searched by LRN or name and filtered by section. */
    protected function renderIndex(LearnerPage $page, ?string $query, ?int $sectionId, int $number): Response
    {
        /** @var array{school: SchoolData, schoolYear: ?array{name: string}, enrollments?: list<EnrollmentData>,
         *     page?: int, matches?: int, hasNext?: bool, sections?: list<SectionData>, count?: int, limit?: int,
         *     section?: ?SectionData, refusal?: ?string} $list */
        $list = $this->ask(new ListLearnersCommand($page->schoolId, $query, $sectionId, $number));
        $vars = [
            'school' => $list['school'],
            'school_year' => $list['schoolYear'],
            'layout' => $page->layout,
            'routes' => $page->routes,
            'route_params' => $page->routeParams,
        ];
        if (null === $list['schoolYear']) {
            return $this->render('@Learners/learners/index.html.twig', $vars);
        }

        return $this->render('@Learners/learners/index.html.twig', $vars + [
            'fixed_section' => null,
            'enrollments' => $list['enrollments'] ?? [],
            'page' => $list['page'] ?? 1,
            'matches' => $list['matches'] ?? 0,
            'has_next' => $list['hasNext'] ?? false,
            'sections' => $list['sections'] ?? [],
            'count' => $list['count'] ?? 0,
            'limit' => $list['limit'] ?? 0,
            'query' => $query,
            'section' => $list['section'] ?? null,
            'refusal' => $list['refusal'] ?? null,
        ]);
    }

    protected function renderClassList(LearnerPage $page, int $sectionId): Response
    {
        /** @var array{school: SchoolData, schoolYear: array{name: string}, fixedSection: ?SectionData,
         *     section: SectionData, enrollments: list<EnrollmentData>, limit: ?int} $list */
        $list = $this->ask(new GetClassListCommand($page->actorId, $page->schoolId, $page->fixedSectionId, $sectionId));

        return $this->render('@Learners/learners/class_list.html.twig', $this->pageVars($page, $list) + [
            'section' => $list['section'],
            'enrollments' => $list['enrollments'],
            'limit' => $list['limit'],
        ]);
    }

    /** Step 1 asks for the LRN (query parameter `lrn`); step 2 shows what fits the learner found. */
    protected function handleEnroll(Request $request, LearnerPage $page): Response
    {
        $lrn = trim($request->query->getString('lrn'));
        $valid = 1 === preg_match('/^\d{12}$/', $lrn);
        /** @var array{school: SchoolData, schoolYear: array{name: string}, fixedSection: ?SectionData,
         *     lookup: ?array{kind: LookupKind, learner: ?LearnerData, enrollment: ?EnrollmentData,
         *     sections: ?list<SectionData>, canRequestTransfer: bool}} $enroll */
        $enroll = $this->ask(new GetEnrollPageCommand(
            $page->actorId,
            $page->schoolId,
            $page->fixedSectionId,
            $valid ? $lrn : null,
        ));
        $vars = $this->pageVars($page, $enroll)
            + ['lrn' => $lrn, 'lrn_error' => null, 'result' => null, 'form' => null, 'transfer_form' => null];
        if ('' === $lrn) {
            return $this->render('@Learners/learners/enroll.html.twig', $vars);
        }
        if (!$valid) {
            return $this->render(
                '@Learners/learners/enroll.html.twig',
                ['lrn_error' => 'The LRN must be exactly 12 digits.'] + $vars,
                new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
            );
        }

        $result = $enroll['lookup'];
        \assert(null !== $result);
        $vars['result'] = $result;
        $choices = null !== $result['sections'] ? self::choices($result['sections']) : null;
        if (LookupKind::Here === $result['kind']) {
            return $this->render('@Learners/learners/enroll.html.twig', $vars);
        }
        if (LookupKind::Elsewhere === $result['kind']) {
            if (null !== $choices && $result['canRequestTransfer'] && null !== $result['learner']) {
                $request = new RequestTransferCommand(
                    $page->actorId,
                    $page->schoolId,
                    $lrn,
                    (int) $result['learner']['id'],
                );
                $vars['transfer_form'] = $this->createForm(TransferRequestFormType::class, $request, [
                    'sections' => $choices,
                    'action' => $this->generateUrl(
                        'school_transfers_request',
                        ['id' => $page->schoolId, 'lrn' => $lrn],
                    ),
                ]);
            }

            return $this->render('@Learners/learners/enroll.html.twig', $vars);
        }

        $isNew = LookupKind::New === $result['kind'];
        $command = new EnrollLearnerCommand(
            $page->actorId,
            $page->schoolId,
            $page->fixedSectionId,
            $lrn,
            $isNew ? null : $result['learner']['id'] ?? null,
            $isNew ? LearnerDetails::forNew($lrn) : null,
        );
        $form = $this->createForm(EnrollFormType::class, $command, ['sections' => $choices]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $enrolled = $this->useCaseBus->handle($command);
            if (null === $refusal = $this->refusal($enrolled)) {
                /** @var array{fullName: string, username: string, password: ?string, sectionId: int,
                 *     sectionLabel: string} $saved */
                $saved = $enrolled->data();
                if (null !== $saved['password']) {
                    $this->addCredentials(
                        'Learner enrolled',
                        $saved['fullName'],
                        $saved['username'],
                        $saved['password'],
                    );
                } else {
                    $this->addFlash(
                        'success',
                        \sprintf('Enrolled %s in %s.', $saved['fullName'], $saved['sectionLabel']),
                    );
                }

                return $this->redirectToClassList($page, $saved['sectionId']);
            }
            $form->addError(new FormError($refusal));
        }

        return $this->render('@Learners/learners/enroll.html.twig', ['form' => $form] + $vars);
    }

    protected function renderShow(LearnerPage $page, int $learnerId, ?int $toSchoolId = null): Response
    {
        /** @var array{school: SchoolData, schoolYear: array{name: string}, fixedSection: ?SectionData,
         *     learner: LearnerData, current: ?EnrollmentData, history: list<EnrollmentData>,
         *     transfers: list<array<string, mixed>>, remarksText: ?string, moveSections: list<SectionData>,
         *     transferSchools: list<array<string, mixed>>, transferToSchool: ?array<string, mixed>,
         *     transferSections: list<SectionData>, can: array<string, bool>} $learner */
        $learner = $this->ask(new GetLearnerCommand(
            $page->actorId,
            $page->schoolId,
            $page->fixedSectionId,
            $learnerId,
            $toSchoolId,
        ));

        return $this->render('@Learners/learners/show.html.twig', $this->pageVars($page, $learner) + [
            'learner' => $learner['learner'],
            'current' => $learner['current'],
            'history' => $learner['history'],
            'transfers' => $learner['transfers'],
            'remarks_text' => $learner['remarksText'],
            'move_sections' => $learner['moveSections'],
            'transfer_schools' => $learner['transferSchools'],
            'transfer_to_school' => $learner['transferToSchool'],
            'transfer_sections' => $learner['transferSections'],
            'photo_form' => $this->createForm(ImageUploadFormType::class, options: [
                'action' => $this->generateUrl(
                    $page->routes . '_photo',
                    $page->routeParams + ['learner' => $learnerId],
                ),
            ]),
            'can' => $learner['can'],
        ]);
    }

    protected function handleEdit(Request $request, LearnerPage $page, int $learnerId): Response
    {
        /** @var array{school: SchoolData, schoolYear: array{name: string}, fixedSection: ?SectionData,
         *     learner: LearnerData, withLrn: bool} $learner */
        $learner = $this->ask(
            new GetLearnerFormCommand($page->actorId, $page->schoolId, $page->fixedSectionId, $learnerId),
        );
        $command = new UpdateLearnerCommand(
            $page->actorId,
            $page->schoolId,
            $page->fixedSectionId,
            $learnerId,
            LearnerDetails::fromLearner($learner['learner']),
            $learner['withLrn'],
        );
        $form = $this->createForm(LearnerFormType::class, $command->details, ['with_lrn' => $learner['withLrn']]);
        $form->handleRequest($request);
        $mistyped = $form->isSubmitted() && !$form->isValid();

        if ($form->isSubmitted() && $form->isValid()) {
            $saved = $this->useCaseBus->handle($command);
            if (null === $refusal = $this->refusal($saved)) {
                $this->addFlash('success', \sprintf('Saved %s.', self::fullName($saved)));

                return $this->redirectToShow($page, $learnerId);
            }
            $form->addError(new FormError($refusal));
        }

        $shown = $learner['learner'];
        if ($mistyped) {
            // the page heading shows what was typed until it is corrected
            $shown['user']['fullName'] = $command->details->fullName();
            $shown['lrn'] = $command->details->lrn;
        }

        return $this->render('@Learners/learners/form.html.twig', $this->pageVars($page, $learner) + [
            'form' => $form,
            'learner' => $shown,
        ]);
    }

    /** Uploads the ID photo of a learner enrolled here; errors come back as a flash message. */
    protected function handlePhoto(Request $request, LearnerPage $page, int $learnerId): Response
    {
        // the learner must be one this page may edit before the upload is looked at
        $this->ask(new GetEnrollmentRecordCommand($page->actorId, $page->schoolId, $page->fixedSectionId, $learnerId));
        $form = $this->createForm(ImageUploadFormType::class);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $errors = $form->getErrors(true);
            $message = \count($errors) > 0 ? $errors[0]->getMessage() : 'The photo could not be uploaded.';
            $this->addFlash('error', $message);

            return $this->redirectToShow($page, $learnerId);
        }

        $file = $form->get('image')->getData();
        \assert($file instanceof File);

        return $this->savePhoto($page, $learnerId, $file->getPathname(), 'Photo updated.');
    }

    /** Removes the ID photo; the CSRF token is checked by the route. */
    protected function handlePhotoRemove(LearnerPage $page, int $learnerId): Response
    {
        return $this->savePhoto($page, $learnerId, null, 'Photo removed.');
    }

    /** This school year's SF1 record of a learner enrolled here: learning modality and remarks. */
    protected function handleEnrollmentRecord(Request $request, LearnerPage $page, int $learnerId): Response
    {
        /** @var array{school: SchoolData, schoolYear: array{name: string}, fixedSection: ?SectionData,
         *     learner: LearnerData, enrollment: EnrollmentData, automaticRemark: ?string} $record */
        $record = $this->ask(
            new GetEnrollmentRecordCommand($page->actorId, $page->schoolId, $page->fixedSectionId, $learnerId),
        );
        $command = new UpdateEnrollmentRecordCommand(
            $page->actorId,
            $page->schoolId,
            $page->fixedSectionId,
            $learnerId,
        );
        $command->learningModality = $record['enrollment']['learningModality'];
        $command->remarkCode = $record['enrollment']['remarkCode'];
        $command->remarkDetails = $record['enrollment']['remarkDetails'];
        $command->remarkDate = $record['enrollment']['remarkDate'];
        $form = $this->createForm(EnrollmentRecordFormType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $saved = $this->useCaseBus->handle($command);
            if (null === $refusal = $this->refusal($saved)) {
                $this->addFlash('success', \sprintf('Saved %s.', self::fullName($saved)));

                return $this->redirectToShow($page, $learnerId);
            }
            $form->addError(new FormError($refusal));
        }

        return $this->render('@Learners/learners/enrollment_record.html.twig', $this->pageVars($page, $record) + [
            'form' => $form,
            'learner' => $record['learner'],
            'enrollment' => $record['enrollment'],
            'automatic_remark' => $record['automaticRemark'],
        ]);
    }

    protected function handleMove(Request $request, LearnerPage $page, int $learnerId): Response
    {
        $sectionId = $request->getPayload()->getInt('section');
        $moved = $this->useCaseBus->handle(
            new MoveLearnerCommand($page->actorId, $page->schoolId, $page->fixedSectionId, $learnerId, $sectionId),
        );
        if (null !== $refusal = $this->refusal($moved)) {
            $this->addFlash('error', $refusal);
        } else {
            /** @var array{fullName: string, sectionLabel: string} $data */
            $data = $moved->data();
            $this->addFlash('success', \sprintf('Moved %s to %s.', $data['fullName'], $data['sectionLabel']));
        }

        return $this->redirectToShow($page, $learnerId);
    }

    /** An administrator moves the learner to a section of another school at once. */
    protected function handleTransfer(Request $request, LearnerPage $page, int $learnerId): Response
    {
        $sectionId = $request->getPayload()->getInt('section');
        $to = $this->sections->findSection($sectionId)
            ?? throw $this->createNotFoundException(TransferLearnerHandler::NOT_ELSEWHERE);
        $transferred = $this->useCaseBus->handle(new TransferLearnerCommand(
            $page->actorId,
            $page->schoolId,
            $page->fixedSectionId,
            $learnerId,
            $sectionId,
            (int) $to->getSchool()->getId(),
        ));
        if (null !== $refusal = $this->refusal($transferred)) {
            $this->addFlash('error', $refusal);
        } else {
            /** @var array{fullName: string, sectionLabel: string, schoolName: string} $data */
            $data = $transferred->data();
            $this->addFlash(
                'success',
                \sprintf('Transferred %s to %s at %s.', $data['fullName'], $data['sectionLabel'], $data['schoolName']),
            );
        }

        return $this->redirectToShow($page, $learnerId);
    }

    protected function handleUnenroll(LearnerPage $page, int $learnerId): Response
    {
        $unenrolled = $this->useCaseBus->handle(
            new UnenrollLearnerCommand($page->actorId, $page->schoolId, $page->fixedSectionId, $learnerId),
        );
        if (null !== $refusal = $this->refusal($unenrolled)) {
            $this->addFlash('error', $refusal);

            return $this->redirectToShow($page, $learnerId);
        }
        /** @var array{fullName: string, sectionLabel: string} $data */
        $data = $unenrolled->data();
        $this->addFlash('success', \sprintf('Unenrolled %s from %s.', $data['fullName'], $data['sectionLabel']));

        return $this->redirectToRoute($page->routes, $page->routeParams);
    }

    protected function handleResetPassword(LearnerPage $page, int $learnerId): Response
    {
        $reset = $this->useCaseBus->handle(
            new ResetLearnerPasswordCommand($page->actorId, $page->schoolId, $page->fixedSectionId, $learnerId),
        );
        if (null !== $refusal = $this->refusal($reset)) {
            $this->addFlash('error', $refusal);
        } else {
            /** @var array{fullName: string, username: string, password: string} $data */
            $data = $reset->data();
            $this->addCredentials('Password reset', $data['fullName'], $data['username'], $data['password']);
        }

        return $this->redirectToShow($page, $learnerId);
    }

    /** The file a download query returns, sent as an attachment of $type. */
    protected function download(Query $query, string $type): Response
    {
        /** @var array{fileName: string, content: string} $file */
        $file = $this->ask($query);
        // section names may have an Ñ; old browsers get underscores
        $fallback = (string) preg_replace('/[^\x20-\x7E]/', '_', $file['fileName']);

        return new Response($file['content'], Response::HTTP_OK, [
            'Content-Type' => $type,
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $file['fileName'],
                $fallback,
            ),
        ]);
    }

    /** The data of a page query; anything not found is a 404, anything not allowed a 403. */
    protected function ask(Query $query): mixed
    {
        $result = $this->queryBus->handle($query);
        $this->refusal($result);

        return $result->data();
    }

    /**
     * The message of a refused change, or null when it was saved. A change another request saved first
     * gets the busy message; anything not found is a 404, anything not allowed a 403.
     */
    protected function refusal(Result $result): ?string
    {
        if ($result->exception() instanceof UniqueConstraintViolationException) {
            return LearnerEnrollment::BUSY;
        }
        $failure = $this->domainFailure($result);
        if (
            $failure instanceof LearnerNotFoundException
            || $failure instanceof SectionNotFoundException
            || $failure instanceof SchoolYearNotFoundException
            || $failure instanceof SchoolNotFoundException
            || $failure instanceof AccountNotFoundException
            || $failure instanceof TransferNotFoundException
        ) {
            throw $this->createNotFoundException($failure->getMessage());
        }
        if ($failure instanceof LearnersAccessDeniedException) {
            throw $this->createAccessDeniedException($failure->getMessage());
        }

        return $failure?->getMessage();
    }

    /** The class list: its own route on the school pages, the list itself on an adviser's. */
    protected function redirectToClassList(LearnerPage $page, int $sectionId): Response
    {
        return null !== $page->fixedSectionId
            ? $this->redirectToRoute($page->routes, $page->routeParams)
            : $this->redirectToRoute($page->routes . '_class', $page->routeParams + ['section' => $sectionId]);
    }

    protected function redirectToShow(LearnerPage $page, int $learnerId): Response
    {
        return $this->redirectToRoute($page->routes . '_show', $page->routeParams + ['learner' => $learnerId]);
    }

    /**
     * @param PageData $data
     *
     * @return array<string, mixed>
     */
    protected function pageVars(LearnerPage $page, array $data): array
    {
        return [
            'school' => $data['school'],
            'school_year' => $data['schoolYear'],
            'fixed_section' => $data['fixedSection'],
            'layout' => $page->layout,
            'routes' => $page->routes,
            'route_params' => $page->routeParams,
        ];
    }

    private function savePhoto(LearnerPage $page, int $learnerId, ?string $path, string $savedMessage): Response
    {
        $saved = $this->useCaseBus->handle(
            new UpdateLearnerPhotoCommand($page->actorId, $page->schoolId, $page->fixedSectionId, $learnerId, $path),
        );
        $refusal = $this->refusal($saved);
        $this->addFlash(null === $refusal ? 'success' : 'error', $refusal ?? $savedMessage);

        return $this->redirectToShow($page, $learnerId);
    }

    private function addCredentials(string $title, string $fullName, string $username, string $password): void
    {
        $this->addFlash('credentials', [
            'title' => $title,
            'fullName' => $fullName,
            'username' => $username,
            'password' => $password,
        ]);
    }

    private static function fullName(Result $result): string
    {
        /** @var array{fullName: string} $data */
        $data = $result->data();

        return $data['fullName'];
    }

    /**
     * Section picker choices: label => id.
     *
     * @param list<SectionData> $sections
     *
     * @return array<string, ?int>
     */
    protected static function choices(array $sections): array
    {
        return array_column($sections, 'id', 'label');
    }
}
