<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Controller;

use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Application\Query\Command\GetSchoolTeacherCommand;
use App\Schools\Application\Query\Command\GetTeacherSeatCommand;
use App\Schools\Application\Query\Command\ListSchoolTeachersCommand;
use App\Schools\Application\UseCase\Command\AddExistingTeacherCommand;
use App\Schools\Application\UseCase\Command\BringBackTeachersCommand;
use App\Schools\Application\UseCase\Command\CreateTeacherCommand;
use App\Schools\Application\UseCase\Command\ResetTeacherPasswordCommand;
use App\Schools\Application\UseCase\Command\ToggleTeacherActiveCommand;
use App\Schools\Application\UseCase\Command\UpdateTeacherCommand;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Exception\TeacherNotFoundException;
use App\Schools\Domain\Model\School;
use App\Schools\Presentation\Form\ExistingTeacherFormType;
use App\Schools\Presentation\Form\TeacherFormType;
use App\Schools\Presentation\Security\SchoolVoter;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The teachers of one school in the current school year. Shared by administrators
 * (any school) and principals (their own school).
 *
 * @phpstan-import-type SchoolData from \App\Schools\Application\Transformer\SchoolTransformer
 * @phpstan-import-type TeacherData from \App\Schools\Application\Transformer\TeacherTransformer
 */
#[Route('/schools/{id}/teachers', requirements: ['id' => '\d+'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolTeacherController extends BusController
{
    /** Shown when a double-submitted form clashes with the change it already saved. */
    public const ALREADY_SAVED = 'This was already saved by another request. Please check the list and try again.';

    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('', name: 'school_teachers', methods: ['GET'])]
    public function index(School $school): Response
    {
        $result = $this->queryBus->handle(new ListSchoolTeachersCommand((int) $school->getId()));
        $this->domainFailure($result);
        /** @var array{school: SchoolData, schoolYear: mixed, assignments: list<mixed>, used: int,
         *     addRefusal: ?string, bringBackFrom: mixed} $list */
        $list = $result->data();

        return $this->render('@Schools/school_teachers/index.html.twig', [
            'school' => $school,
            'school_year' => $list['schoolYear'],
            'assignments' => $list['assignments'],
            'used' => $list['used'],
            'add_refusal' => $list['addRefusal'],
            'bring_back_from' => $list['bringBackFrom'],
        ]);
    }

    #[Route('/new', name: 'school_teachers_new', methods: ['GET', 'POST'])]
    public function new(Request $request, School $school): Response
    {
        $schoolId = (int) $school->getId();
        $seat = $this->seat($schoolId);
        if (null !== $seat['refusal']) {
            $this->addFlash('error', $seat['refusal']);

            return $this->redirectToList($schoolId);
        }

        $command = new CreateTeacherCommand($schoolId);
        $form = $this->createForm(TeacherFormType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $result = $this->useCaseBus->handle($command);
            if (null !== $refusal = $this->refusal($result)) {
                $this->addFlash('error', $refusal);
            } else {
                /** @var array{fullName: string, username: string, password: string} $teacher */
                $teacher = $result->data();
                $this->addCredentials('Teacher created', $teacher);
            }

            return $this->redirectToList($schoolId);
        }

        return $this->render('@Schools/school_teachers/form.html.twig', [
            'form' => $form,
            'school' => $school,
            'teacher' => null,
        ]);
    }

    #[Route('/add-existing', name: 'school_teachers_add_existing', methods: ['GET', 'POST'])]
    public function addExisting(Request $request, School $school): Response
    {
        $schoolId = (int) $school->getId();
        $seat = $this->seat($schoolId);
        if (null !== $seat['refusal']) {
            $this->addFlash('error', $seat['refusal']);

            return $this->redirectToList($schoolId);
        }

        $command = new AddExistingTeacherCommand($schoolId);
        $form = $this->createForm(ExistingTeacherFormType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $result = $this->useCaseBus->handle($command);
            $refusal = $this->refusal($result);
            if (null === $refusal) {
                /** @var array{fullName: string, school: string} $added */
                $added = $result->data();
                $this->addFlash(
                    'success',
                    \sprintf('%s was added to the teachers of %s.', $added['fullName'], $added['school']),
                );

                return $this->redirectToList($schoolId);
            }
            $form->get('username')->addError(new FormError($refusal));
        }

        return $this->render('@Schools/school_teachers/add_existing.html.twig', [
            'form' => $form,
            'school' => $school,
        ]);
    }

    #[Route(
        '/{teacher}/edit',
        name: 'school_teachers_edit',
        requirements: ['teacher' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function edit(Request $request, School $school, int $teacher): Response
    {
        $schoolId = (int) $school->getId();
        $found = $this->queryBus->handle(new GetSchoolTeacherCommand($schoolId, $teacher));
        $this->notFoundOnFailure($found);
        /** @var array{school: SchoolData, teacher: TeacherData} $page */
        $page = $found->data();

        $command = UpdateTeacherCommand::fromTeacher($schoolId, $page['teacher']);
        $form = $this->createForm(TeacherFormType::class, $command);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $result = $this->useCaseBus->handle($command);
            $this->notFoundOnFailure($result);
            /** @var array{fullName: string} $saved */
            $saved = $result->data();
            $this->addFlash('success', \sprintf('Saved changes to %s.', $saved['fullName']));

            return $this->redirectToList($schoolId);
        }

        return $this->render('@Schools/school_teachers/form.html.twig', [
            'form' => $form,
            'school' => $school,
            'teacher' => $page['teacher'],
        ]);
    }

    #[Route('/{teacher}/toggle', name: 'school_teachers_toggle', requirements: ['teacher' => '\d+'], methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"teacher-" ~ args["teacher"]'), tokenKey: '_token')]
    public function toggle(School $school, int $teacher): Response
    {
        $schoolId = (int) $school->getId();
        $result = $this->useCaseBus->handle(new ToggleTeacherActiveCommand($schoolId, $teacher));
        if (null !== $refusal = $this->refusal($result)) {
            $this->addFlash('error', $refusal);
        } else {
            /** @var array{fullName: string, active: bool} $toggled */
            $toggled = $result->data();
            $this->addFlash('success', \sprintf(
                '%s is now %s.',
                $toggled['fullName'],
                $toggled['active'] ? 'active' : 'inactive',
            ));
        }

        return $this->redirectToList($schoolId);
    }

    #[Route(
        '/{teacher}/reset-password',
        name: 'school_teachers_reset_password',
        requirements: ['teacher' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(new Expression('"teacher-" ~ args["teacher"]'), tokenKey: '_token')]
    public function resetPassword(School $school, int $teacher): Response
    {
        $schoolId = (int) $school->getId();
        $result = $this->useCaseBus->handle(new ResetTeacherPasswordCommand($schoolId, $teacher));
        $this->notFoundOnFailure($result);
        /** @var array{fullName: string, username: string, password: string} $reset */
        $reset = $result->data();
        $this->addCredentials('Password reset', $reset);

        return $this->redirectToList($schoolId);
    }

    #[Route('/bring-back', name: 'school_teachers_bring_back', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"bring-back-" ~ args["school"].getId()'), tokenKey: '_token')]
    public function bringBack(School $school): Response
    {
        $schoolId = (int) $school->getId();
        $result = $this->useCaseBus->handle(new BringBackTeachersCommand($schoolId));
        if (null !== $refusal = $this->refusal($result)) {
            $this->addFlash('error', $refusal);
        } else {
            /** @var array{added: int, skipped: list<string>} $back */
            $back = $result->data();
            $this->addFlash('success', \sprintf('Brought back %d teacher(s).', $back['added']));
            if ([] !== $back['skipped']) {
                $this->addFlash('warning', 'Not brought back: ' . implode('; ', $back['skipped']));
            }
        }

        return $this->redirectToList($schoolId);
    }

    /** @return array{school: SchoolData, refusal: ?string} */
    private function seat(int $schoolId): array
    {
        $result = $this->queryBus->handle(new GetTeacherSeatCommand($schoolId));
        $this->notFoundOnFailure($result);
        /** @var array{school: SchoolData, refusal: ?string} $seat */
        $seat = $result->data();

        return $seat;
    }

    /**
     * The message to show when a change was refused (a plan rule, a busy school, a double submit), or null.
     * A missing school year or teacher still ends in a 404, and any other failure in an error page.
     */
    private function refusal(Result $result): ?string
    {
        if ($result->exception() instanceof UniqueConstraintViolationException) {
            return self::ALREADY_SAVED;
        }
        $this->notFoundOnFailure($result);

        return $this->domainFailure($result)?->getMessage();
    }

    private function notFoundOnFailure(Result $result): void
    {
        $failure = $this->domainFailure($result);
        if ($failure instanceof SchoolYearNotFoundException || $failure instanceof TeacherNotFoundException) {
            throw $this->createNotFoundException($failure->getMessage());
        }
    }

    /** @param array{fullName: string, username: string, password: string} $teacher */
    private function addCredentials(string $title, array $teacher): void
    {
        $this->addFlash('credentials', ['title' => $title] + $teacher);
    }

    private function redirectToList(int $schoolId): Response
    {
        return $this->redirectToRoute('school_teachers', ['id' => $schoolId]);
    }
}
