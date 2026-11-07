<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Application\Query\Command\GetPrincipalAssignmentCommand;
use App\Schools\Application\UseCase\Command\AssignPrincipalCommand;
use App\Schools\Application\UseCase\Command\CopyPrincipalsCommand;
use App\Schools\Application\UseCase\Command\RemovePrincipalCommand;
use App\Schools\Domain\Exception\AssignmentException;
use App\Schools\Presentation\Form\PrincipalAssignmentFormType;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin assigns principals to schools per school year.
 *
 * @phpstan-import-type SchoolData from \App\Schools\Application\Transformer\SchoolTransformer
 * @phpstan-import-type SchoolYearData from \App\Schools\Application\Transformer\SchoolYearTransformer
 */
#[IsGranted(User::ROLE_ADMIN)]
#[Route('/admin/schools')]
class SchoolPrincipalController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route(
        '/{id}/principal',
        name: 'admin_schools_principal',
        requirements: ['id' => '\d+'],
        methods: ['GET', 'POST'],
    )]
    public function principal(Request $request, int $id, #[MapQueryParameter] ?int $sy = null): Response
    {
        $result = $this->queryBus->handle(new GetPrincipalAssignmentCommand($id, $sy));
        $this->notFoundOnFailure($result);
        /** @var array{school: SchoolData, schoolYear: SchoolYearData, current: ?array{id: int, fullName: string},
         *     principals: list<array{id: int, fullName: string}>} $page */
        $page = $result->data();

        $command = new AssignPrincipalCommand($id, $sy);
        $command->principalId = $page['current']['id'] ?? null;
        $form = $this->createForm(PrincipalAssignmentFormType::class, $command, ['principals' => $page['principals']]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $assigned = $this->useCaseBus->handle($command);
            $this->notFoundOnFailure($assigned, refusalsAllowed: true);
            if (null !== $refusal = $this->domainFailure($assigned)) {
                $this->addFlash('error', $refusal->getMessage());

                return $this->redirectToList($refusal instanceof AssignmentException ? $refusal->schoolYearId() : null);
            }
            /** @var array{principal: string, school: string, schoolYear: string, schoolYearId: int} $done */
            $done = $assigned->data();
            $this->addFlash('success', \sprintf(
                '%s is now the principal of %s for %s.',
                $done['principal'],
                $done['school'],
                $done['schoolYear'],
            ));

            return $this->redirectToList($done['schoolYearId']);
        }

        return $this->render('@Schools/admin/schools/assign_principal.html.twig', [
            'form' => $form,
            'school' => $page['school'],
            'school_year' => $page['schoolYear'],
            'current' => $page['current'],
        ]);
    }

    #[Route(
        '/{id}/principal/remove',
        name: 'admin_schools_principal_remove',
        requirements: ['id' => '\d+'],
        methods: ['POST'],
    )]
    #[IsCsrfTokenValid(new Expression('"school-principal-" ~ args["id"]'), tokenKey: '_token')]
    public function removePrincipal(int $id, #[MapQueryParameter] ?int $sy = null): Response
    {
        $result = $this->useCaseBus->handle(new RemovePrincipalCommand($id, $sy));
        $this->notFoundOnFailure($result);
        /** @var array{school: string, schoolYear: string, schoolYearId: int} $done */
        $done = $result->data();
        $this->addFlash(
            'success',
            \sprintf('%s no longer has a principal for %s.', $done['school'], $done['schoolYear']),
        );

        return $this->redirectToList($done['schoolYearId']);
    }

    #[Route('/copy-principals', name: 'admin_schools_copy_principals', methods: ['POST'])]
    #[IsCsrfTokenValid('copy-principals', tokenKey: '_token')]
    public function copyPrincipals(#[MapQueryParameter] ?int $sy = null): Response
    {
        $result = $this->useCaseBus->handle(new CopyPrincipalsCommand($sy));
        $this->notFoundOnFailure($result, refusalsAllowed: true);
        if (null !== $refusal = $this->domainFailure($result)) {
            $this->addFlash('error', $refusal->getMessage());

            return $this->redirectToList($refusal instanceof AssignmentException ? $refusal->schoolYearId() : null);
        }
        /** @var array{copied: int, skipped: list<string>, schoolYearId: int} $copy */
        $copy = $result->data();
        $this->addFlash('success', \sprintf('Copied %d principal assignment(s).', $copy['copied']));
        if ([] !== $copy['skipped']) {
            $this->addFlash('warning', 'Skipped: ' . implode('; ', $copy['skipped']));
        }

        return $this->redirectToList($copy['schoolYearId']);
    }

    /** A missing school or school year ends in a 404; assignment refusals go back to the caller when allowed. */
    private function notFoundOnFailure(Result $result, bool $refusalsAllowed = false): void
    {
        $failure = $this->domainFailure($result);
        if (null !== $failure && !($refusalsAllowed && $failure instanceof AssignmentException)) {
            throw $this->createNotFoundException($failure->getMessage());
        }
    }

    private function redirectToList(?int $schoolYearId): Response
    {
        return $this->redirectToRoute('admin_schools', null !== $schoolYearId ? ['sy' => $schoolYearId] : []);
    }
}
