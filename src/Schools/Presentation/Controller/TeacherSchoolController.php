<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Application\Query\Command\GetTeacherSchoolCommand;
use App\Schools\Application\UseCase\Command\AssignTeacherSchoolCommand;
use App\Schools\Application\UseCase\Command\RemoveTeacherSchoolCommand;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Exception\TeacherNotFoundException;
use App\Schools\Presentation\Form\TeacherSchoolFormType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin assigns a teacher account to a school for the current school year.
 *
 * @phpstan-import-type SchoolData from \App\Schools\Application\Transformer\SchoolTransformer
 * @phpstan-import-type SchoolYearData from \App\Schools\Application\Transformer\SchoolYearTransformer
 * @phpstan-import-type TeacherData from \App\Schools\Application\Transformer\TeacherTransformer
 */
#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin')]
class TeacherSchoolController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(
        '/accounts/{id}/school',
        name: 'admin_account_school',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function school(Request $request, int $id): Response
    {
        $result = $this->queryBus->handle(new GetTeacherSchoolCommand($id));
        $this->notFoundOnFailure($result);
        /** @var array{account: TeacherData, schoolYear: SchoolYearData, current: ?SchoolData,
         *     schools: list<array{id: int, label: string}>} $page */
        $page = $result->data();

        $command = new AssignTeacherSchoolCommand($id);
        $command->schoolId = $page['current']['id'] ?? null;
        $form = $this->createForm(TeacherSchoolFormType::class, $command, ['schools' => $page['schools']]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $assigned = $this->useCaseBus->handle($command);
            $refusal = $this->refusal($assigned);
            if (null === $refusal) {
                /** @var array{fullName: string, school: string, schoolYear: string} $done */
                $done = $assigned->data();
                $this->addFlash('success', \sprintf(
                    '%s teaches at %s for %s.',
                    $done['fullName'],
                    $done['school'],
                    $done['schoolYear'],
                ));

                return $this->redirectToRoute('admin_accounts_by_role', ['slug' => 'teachers']);
            }
            $form->get('school')->addError(new FormError($refusal));
        }

        return $this->render('@Schools/admin/accounts/school.html.twig', [
            'form' => $form,
            'account' => $page['account'],
            'school_year' => $page['schoolYear'],
            'current' => $page['current'],
        ]);
    }

    #[Route(
        '/accounts/{id}/school/remove',
        name: 'admin_account_school_remove',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(new Expression('"account-school-" ~ args["id"]'), tokenKey: '_token')]
    public function removeSchool(int $id): Response
    {
        $result = $this->useCaseBus->handle(new RemoveTeacherSchoolCommand($id));
        $this->notFoundOnFailure($result);
        if (null !== $refusal = $this->domainFailure($result)) {
            $this->addFlash('error', $refusal->getMessage());
        } else {
            /** @var array{fullName: string, schoolYear: string} $done */
            $done = $result->data();
            $this->addFlash(
                'success',
                \sprintf('%s no longer has a school for %s.', $done['fullName'], $done['schoolYear']),
            );
        }

        return $this->redirectToRoute('admin_accounts_by_role', ['slug' => 'teachers']);
    }

    /** The message to show when the move was refused (a rule, a full plan, a busy school, a double submit), or null. */
    private function refusal(Result $result): ?string
    {
        if ($result->exception() instanceof UniqueConstraintViolationException) {
            return SchoolTeacherController::ALREADY_SAVED;
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
}
