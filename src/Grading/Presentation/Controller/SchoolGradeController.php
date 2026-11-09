<?php

declare(strict_types=1);

namespace App\Grading\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\UseCase;
use App\Grading\Application\Query\Command\GetFailingLearnersCommand;
use App\Grading\Application\Query\Command\GetGradeLockCommand;
use App\Grading\Application\Query\Command\GetSchoolGradesCommand;
use App\Grading\Application\Query\Command\GetSectionGradesCommand;
use App\Grading\Application\UseCase\Command\CloseGradingPeriodCommand;
use App\Grading\Application\UseCase\Command\LockClassGradesCommand;
use App\Grading\Application\UseCase\Command\LockGradingPeriodCommand;
use App\Grading\Application\UseCase\Command\OpenGradingPeriodCommand;
use App\Grading\Application\UseCase\Command\UnlockClassGradesCommand;
use App\Grading\Application\UseCase\Command\UnlockGradingPeriodCommand;
use App\Grading\Presentation\Form\GradeLockFormType;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The school's grades — admin for any school, principal for their own: every class's progress for a
 * grading period, and each section's grades, read-only. Only the principal locks and unlocks.
 */
#[Route('/schools/{id}', requirements: ['id' => '\d+', 'section' => '\d+', 'subject' => '\d+', 'period' => '\d+'])]
#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]
class SchoolGradeController extends AbstractGradingController
{
    #[Route('/grades', name: 'school_grades', methods: ['GET'])]
    public function overview(
        School $school,
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?int $period = null,
    ): Response {
        $page = $this->ask(new GetSchoolGradesCommand((int) $user->getId(), (int) $school->getId(), $period));
        $selected = $page['period'];
        $canLockNow = true === $page['can_lock'] && $selected instanceof GradingPeriod && null === $page['lock'];

        return $this->render('@Grading/grades/school.html.twig', $page + [
            'layout' => $this->layout(),
            'lock_form' => $canLockNow ? $this->lockForm($school, $selected)->createView() : null,
        ]);
    }

    #[Route('/grades/failing', name: 'school_grades_failing', methods: ['GET'])]
    public function failing(
        School $school,
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?int $period = null,
    ): Response {
        return $this->render('@Grading/grades/failing.html.twig', [
            'layout' => $this->layout(),
        ] + $this->ask(new GetFailingLearnersCommand((int) $user->getId(), (int) $school->getId(), $period)));
    }

    #[Route('/sections/{section}/grades', name: 'school_grades_section', methods: ['GET'])]
    public function section(
        School $school,
        int $section,
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?int $period = null,
    ): Response {
        return $this->render('@Grading/grades/school_section.html.twig', [
            'layout' => $this->layout(),
        ] + $this->ask(new GetSectionGradesCommand((int) $user->getId(), (int) $school->getId(), $section, $period)));
    }

    #[Route('/grades/{period}/open', name: 'school_grades_open', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"grade-open-" ~ args["period"]'), tokenKey: '_token')]
    public function open(School $school, int $period, #[CurrentUser] User $user): Response
    {
        $command = new OpenGradingPeriodCommand((int) $user->getId(), (int) $school->getId(), $period);

        return $this->change($school, $period, $command, '%s is open for grading.');
    }

    #[Route('/grades/{period}/close', name: 'school_grades_close', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"grade-close-" ~ args["period"]'), tokenKey: '_token')]
    public function close(School $school, int $period, #[CurrentUser] User $user): Response
    {
        $command = new CloseGradingPeriodCommand((int) $user->getId(), (int) $school->getId(), $period);

        return $this->change($school, $period, $command, '%s grading is closed.');
    }

    #[Route('/grades/{period}/lock', name: 'school_grades_lock', methods: ['POST'])]
    public function lock(Request $request, School $school, int $period, #[CurrentUser] User $user): Response
    {
        $page = $this->ask(new GetGradeLockCommand((int) $user->getId(), (int) $school->getId(), $period));
        $selected = $page['period'];
        \assert($selected instanceof GradingPeriod);

        $form = $this->lockForm($school, $selected)->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $command = new LockGradingPeriodCommand((int) $user->getId(), (int) $school->getId(), $period);

            return $this->change($school, $period, $command, '%s grades are locked.');
        }

        $errors = $form->getErrors(true);
        $this->addFlash('error', \count($errors) > 0 ? $errors[0]->getMessage() : \sprintf(
            'Tick "Yes, lock every class in %s" to lock it.',
            $selected->getLabel(),
        ));

        return $this->backTo($school, $period);
    }

    #[Route('/grades/{period}/unlock', name: 'school_grades_unlock', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"grade-unlock-" ~ args["period"]'), tokenKey: '_token')]
    public function unlock(School $school, int $period, #[CurrentUser] User $user): Response
    {
        $command = new UnlockGradingPeriodCommand((int) $user->getId(), (int) $school->getId(), $period);

        return $this->change($school, $period, $command, '%s grades are unlocked.');
    }

    #[Route(
        '/sections/{section}/subjects/{subject}/grades/{period}/unlock',
        name: 'school_grades_unlock_class',
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        new Expression('"grade-unlock-class-" ~ args["section"] ~ "-" ~ args["subject"] ~ "-" ~ args["period"]'),
        tokenKey: '_token',
    )]
    public function unlockClass(
        School $school,
        int $section,
        int $subject,
        int $period,
        #[CurrentUser] User $user,
    ): Response {
        $command = new UnlockClassGradesCommand(
            (int) $user->getId(),
            (int) $school->getId(),
            $section,
            $subject,
            $period,
        );

        return $this->change($school, $period, $command, '%s is unlocked for corrections.');
    }

    #[Route(
        '/sections/{section}/subjects/{subject}/grades/{period}/lock',
        name: 'school_grades_lock_class',
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(
        new Expression('"grade-lock-class-" ~ args["section"] ~ "-" ~ args["subject"] ~ "-" ~ args["period"]'),
        tokenKey: '_token',
    )]
    public function lockClass(
        School $school,
        int $section,
        int $subject,
        int $period,
        #[CurrentUser] User $user,
    ): Response {
        $command = new LockClassGradesCommand(
            (int) $user->getId(),
            (int) $school->getId(),
            $section,
            $subject,
            $period,
        );

        return $this->change($school, $period, $command, '%s is locked again.');
    }

    /** Sends the change and shows how it went: $success gets the period's or the class's label. */
    private function change(School $school, int $period, UseCase $command, string $success): Response
    {
        $result = $this->useCaseBus->handle($command);
        $refusal = $this->refusal($result);
        if (null === $refusal) {
            $label = $result->data();
            \assert(\is_string($label));
            $this->addFlash('success', \sprintf($success, $label));
        } else {
            $this->addFlash('error', $refusal);
        }

        return $this->backTo($school, $period);
    }

    /** @return FormInterface<mixed> */
    private function lockForm(School $school, GradingPeriod $period): FormInterface
    {
        return $this->createForm(GradeLockFormType::class, null, [
            'period_label' => $period->getLabel(),
            'action' => $this->generateUrl(
                'school_grades_lock',
                ['id' => $school->getId(), 'period' => $period->getId()],
            ),
        ]);
    }

    private function backTo(School $school, int $period): Response
    {
        return $this->redirectToRoute('school_grades', ['id' => $school->getId(), 'period' => $period]);
    }

    private function layout(): string
    {
        return $this->isGranted(User::ROLE_ADMIN)
            ? '@Core/admin/_layout.html.twig'
            : '@Core/principal/_layout.html.twig';
    }
}
