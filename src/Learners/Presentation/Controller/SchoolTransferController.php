<?php

declare(strict_types=1);

namespace App\Learners\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\Result;
use App\Learners\Application\Query\Command\GetTransferCommand;
use App\Learners\Application\Query\Command\GetTransferRequestFormCommand;
use App\Learners\Application\Query\Command\ListTransfersCommand;
use App\Learners\Application\Service\TransferRequests;
use App\Learners\Application\UseCase\Command\ApproveTransferCommand;
use App\Learners\Application\UseCase\Command\CancelTransferCommand;
use App\Learners\Application\UseCase\Command\DeclineTransferCommand;
use App\Learners\Application\UseCase\Command\RequestTransferCommand;
use App\Learners\Presentation\Form\TransferRequestFormType;
use App\Schools\Domain\Exception\PlanLimitException;
use App\Schools\Domain\Model\School;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Transfer requests between schools: the Incoming / Outgoing page, and requesting, approving,
 * declining and cancelling them. The page is for admins and the principal, and, on a plan
 * without a principal, for an adviser of the school (only what leaves their section).
 *
 * @phpstan-import-type SectionData from \App\Learners\Application\Transformer\EnrollmentTransformer
 */
#[Route('/schools/{id}/transfers', requirements: ['id' => '\d+', 'transfer' => '\d+'])]
class SchoolTransferController extends AbstractLearnerController
{
    #[Route('', name: 'school_transfers', methods: ['GET'])]
    public function index(School $school, #[CurrentUser] User $user, #[MapQueryParameter] ?string $tab = null): Response
    {
        /** @var array{school: array{id: ?int, name: string}, schoolYear: ?array{name: string}, tab: string,
         *     adviserOf: ?array{label: string}, requests: list<array<string, mixed>>} $page */
        $page = $this->ask(new ListTransfersCommand((int) $user->getId(), (int) $school->getId(), $tab));

        return $this->render('@Learners/transfers/index.html.twig', [
            'school' => $page['school'],
            'school_year' => $page['schoolYear'],
            'layout' => $this->layout(),
            'tab' => $page['tab'],
            'adviser_of' => $page['adviserOf'],
            'requests' => $page['requests'],
        ]);
    }

    /** A principal asks the learner's current school to let them move into one of their sections (from the enroll page). */
    #[Route('/request', name: 'school_transfers_request', methods: ['POST'])]
    public function request(
        Request $request,
        School $school,
        #[CurrentUser] User $user,
        #[MapQueryParameter] string $lrn = '',
    ): Response {
        /** @var array{learnerId: ?int, sections: list<SectionData>} $found */
        $found = $this->ask(new GetTransferRequestFormCommand((int) $user->getId(), (int) $school->getId(), $lrn));
        $backToEnroll = $this->redirectToRoute('school_learners_enroll', ['id' => $school->getId(), 'lrn' => $lrn]);
        if (null === $found['learnerId']) {
            // enrolled here or unenrolled meanwhile: the enroll page shows what to do now
            return $backToEnroll;
        }

        // the form's CSRF token and its section choices (this school, this year) are checked here
        $command = new RequestTransferCommand((int) $user->getId(), (int) $school->getId(), $lrn, $found['learnerId']);
        $form = $this->createForm(TransferRequestFormType::class, $command, [
            'sections' => self::choices($found['sections']),
        ]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            $errors = $form->getErrors(true);
            $this->addFlash('error', \count($errors) > 0 ? $errors[0]->getMessage() : 'Choose a section.');

            return $backToEnroll;
        }

        $sent = $this->useCaseBus->handle($command);
        if (null !== $refusal = $this->refusal($sent)) {
            $this->addFlash('error', $refusal);

            return $backToEnroll;
        }
        /** @var array{schoolName: string} $data */
        $data = $sent->data();
        $this->addFlash('success', \sprintf('Transfer request sent to %s.', $data['schoolName']));

        return $this->redirectToRoute('school_learners', ['id' => $school->getId()]);
    }

    #[Route('/{transfer}/approve', name: 'school_transfers_approve', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"transfer-" ~ args["transfer"]'), tokenKey: '_token')]
    public function approve(School $school, int $transfer, #[CurrentUser] User $user): Response
    {
        $found = $this->transfer($school, $transfer, $user, outgoing: true);
        if (null === $found) {
            return $this->decided($school, 'outgoing');
        }

        $approved = $this->useCaseBus->handle(new ApproveTransferCommand(
            (int) $user->getId(),
            (int) $school->getId(),
            $transfer,
            $found['learnerId'],
            $found['toSchoolId'],
        ));
        if (null !== $refusal = $this->refusal($approved)) {
            $prefix = $approved->exception() instanceof PlanLimitException ? 'Approval refused: ' : '';
            $this->addFlash('error', $prefix . $refusal);
        } else {
            /** @var array{fullName: string, schoolName: string} $data */
            $data = $approved->data();
            $this->addFlash(
                'success',
                \sprintf('Approved transfer of %s to %s.', $data['fullName'], $data['schoolName']),
            );
        }

        return $this->backTo($school, 'outgoing');
    }

    #[Route('/{transfer}/decline', name: 'school_transfers_decline', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"transfer-" ~ args["transfer"]'), tokenKey: '_token')]
    public function decline(School $school, int $transfer, #[CurrentUser] User $user): Response
    {
        $found = $this->transfer($school, $transfer, $user, outgoing: true);
        if (null === $found) {
            return $this->decided($school, 'outgoing');
        }

        $declined = $this->useCaseBus->handle(
            new DeclineTransferCommand((int) $user->getId(), (int) $school->getId(), $transfer, $found['learnerId']),
        );
        $this->flashDecision($declined, 'Declined transfer of %s.');

        return $this->backTo($school, 'outgoing');
    }

    #[Route('/{transfer}/cancel', name: 'school_transfers_cancel', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"transfer-" ~ args["transfer"]'), tokenKey: '_token')]
    public function cancel(School $school, int $transfer, #[CurrentUser] User $user): Response
    {
        $found = $this->transfer($school, $transfer, $user, outgoing: false);
        if (null === $found) {
            return $this->decided($school, 'incoming');
        }

        $cancelled = $this->useCaseBus->handle(
            new CancelTransferCommand((int) $user->getId(), (int) $school->getId(), $transfer, $found['learnerId']),
        );
        $this->flashDecision($cancelled, 'Cancelled transfer request for %s.');

        return $this->backTo($school, 'incoming');
    }

    /**
     * The pending request to decide on, or null when it was decided meanwhile (another tab, another approver).
     *
     * @return array{learnerId: int, toSchoolId: int}|null
     */
    private function transfer(School $school, int $transfer, User $user, bool $outgoing): ?array
    {
        /** @var array{learnerId: int, toSchoolId: int, pending: bool} $found */
        $found = $this->ask(new GetTransferCommand((int) $user->getId(), (int) $school->getId(), $transfer, $outgoing));

        return $found['pending'] ? ['learnerId' => $found['learnerId'], 'toSchoolId' => $found['toSchoolId']] : null;
    }

    /** A decided request is not an access error: the page's users are told so and sent back to it. */
    private function decided(School $school, string $tab): Response
    {
        $this->addFlash('error', TransferRequests::DECIDED);

        return $this->backTo($school, $tab);
    }

    private function flashDecision(Result $result, string $success): void
    {
        if (null !== $refusal = $this->refusal($result)) {
            $this->addFlash('error', $refusal);

            return;
        }
        /** @var array{fullName: string} $data */
        $data = $result->data();
        $this->addFlash('success', \sprintf($success, $data['fullName']));
    }

    private function backTo(School $school, string $tab): Response
    {
        return $this->redirectToRoute('school_transfers', ['id' => $school->getId(), 'tab' => $tab]);
    }

    private function layout(): string
    {
        return match (true) {
            $this->isGranted(User::ROLE_ADMIN) => 'admin/_layout.html.twig',
            $this->isGranted(User::ROLE_PRINCIPAL) => 'principal/_layout.html.twig',
            default => 'teacher/_layout.html.twig',
        };
    }
}
