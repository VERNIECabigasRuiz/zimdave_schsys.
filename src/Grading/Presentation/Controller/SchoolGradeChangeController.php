<?php

declare(strict_types=1);

namespace App\Grading\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Grading\Application\Query\Command\GetGradeChangeCommand;
use App\Grading\Application\Query\Command\GetGradeChangesCommand;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\UseCase\Command\ApproveGradeChangeCommand;
use App\Grading\Application\UseCase\Command\DeclineGradeChangeCommand;
use App\Grading\Presentation\Form\GradeChangeDeclineFormType;
use App\Schools\Application\Api\SchoolDirectoryInterface;
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

/** The school's grade change requests: the principal approves or declines them; administrators see them. */
#[Route('/schools/{id}/grade-changes', requirements: ['id' => '\d+', 'request' => '\d+'])]
#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]
class SchoolGradeChangeController extends AbstractGradingController
{
    #[Route('', name: 'school_grade_changes', methods: ['GET'])]
    public function index(
        School $school,
        #[CurrentUser] User $user,
        #[MapQueryParameter] ?string $status = null,
    ): Response {
        return $this->page($school, $user, 'decided' === $status ? 'decided' : 'pending');
    }

    #[Route('/{request}/approve', name: 'school_grade_change_approve', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"grade-change-approve-" ~ args["request"]'), tokenKey: '_token')]
    public function approve(School $school, int $request, #[CurrentUser] User $user): Response
    {
        $page = $this->decidable($school, $request, $user);
        $refusal = $this->refusal($this->useCaseBus->handle(new ApproveGradeChangeCommand(
            (int) $user->getId(),
            (int) $school->getId(),
            $request,
            self::id($page['section_id']),
            self::id($page['subject_id']),
        )));
        if (null === $refusal) {
            $this->addFlash('success', 'Approved. The new grades are in.');
        } else {
            $this->addFlash('error', $refusal);
        }

        return $this->redirectToRoute('school_grade_changes', ['id' => $school->getId()]);
    }

    #[Route('/{request}/decline', name: 'school_grade_change_decline', methods: ['POST'])]
    public function decline(Request $httpRequest, School $school, int $request, #[CurrentUser] User $user): Response
    {
        $page = $this->decidable($school, $request, $user);
        if (true !== $page['pending']) {
            // decided meanwhile: its decline form is gone from the page, so the note's errors have nowhere to show
            $this->addFlash('error', 'This request has already been decided.');

            return $this->redirectToRoute('school_grade_changes', ['id' => $school->getId()]);
        }

        $form = $this->declineForm($school, $request)->handleRequest($httpRequest);
        if (!$form->isSubmitted() || !$form->isValid()) {
            // show the field error where the principal typed the note
            return $this->page($school, $user, 'pending', $form, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $note = $form->get('note')->getData();
        $refusal = $this->refusal($this->useCaseBus->handle(new DeclineGradeChangeCommand(
            (int) $user->getId(),
            (int) $school->getId(),
            $request,
            \is_scalar($note) ? (string) $note : '',
        )));
        if (null === $refusal) {
            $this->addFlash('success', 'Declined. The teacher will see your note.');
        } else {
            $this->addFlash('error', $refusal);
        }

        return $this->redirectToRoute('school_grade_changes', ['id' => $school->getId()]);
    }

    /** @return array<string, mixed> the request, after the 404 and 403 checks */
    private function decidable(School $school, int $request, User $user): array
    {
        return $this->ask(new GetGradeChangeCommand(
            (int) $user->getId(),
            (int) $school->getId(),
            $request,
            GradePolicy::CHANGE_DECIDE,
        ));
    }

    /** @param FormInterface<mixed>|null $submitted a decline form posted with errors, shown in place of its blank one */
    private function page(
        School $school,
        User $user,
        string $status,
        ?FormInterface $submitted = null,
        int $code = Response::HTTP_OK,
    ): Response {
        $page = $this->ask(new GetGradeChangesCommand((int) $user->getId(), (int) $school->getId(), $status));
        /** @var list<int> $decidable */
        $decidable = $page['decidable'];
        $forms = [];
        foreach ($decidable as $id) {
            $form = null !== $submitted && $submitted->getName() === 'decline_' . $id
                ? $submitted
                : $this->declineForm($school, $id);
            $forms[$id] = $form->createView();
        }

        return $this->render('@Grading/grades/changes.html.twig', [
            'layout' => $this->layout(),
            'school' => $school,
            'school_year' => $page['school_year'],
            'status' => $page['status'],
            'requests' => $page['requests'],
            'class_labels' => $page['class_labels'],
            'decline_forms' => $forms,
            'pending_count' => $page['pending_count'],
        ], new Response(status: $code));
    }

    /** @return FormInterface<mixed> */
    private function declineForm(School $school, int $request): FormInterface
    {
        return $this->container->get('form.factory')->createNamed(
            'decline_' . $request,
            GradeChangeDeclineFormType::class,
            null,
            [
                'action' => $this->generateUrl(
                    'school_grade_change_decline',
                    ['id' => $school->getId(), 'request' => $request],
                ),
            ],
        );
    }

    private function layout(): string
    {
        return $this->isGranted(User::ROLE_ADMIN)
            ? '@Core/admin/_layout.html.twig'
            : '@Core/principal/_layout.html.twig';
    }

    private static function id(mixed $id): int
    {
        \assert(\is_int($id));

        return $id;
    }
}
