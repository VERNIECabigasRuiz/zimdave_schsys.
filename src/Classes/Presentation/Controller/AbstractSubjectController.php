<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Controller;

use App\Classes\Application\Query\Command\GetSubjectFormCommand;
use App\Classes\Application\Query\Command\ListSubjectsCommand;
use App\Classes\Application\Service\Subject\SubjectScopeKey;
use App\Classes\Application\UseCase\Command\AddDepEdSubjectsCommand;
use App\Classes\Application\UseCase\Command\BringBackSubjectsCommand;
use App\Classes\Application\UseCase\Command\CreateSubjectCommand;
use App\Classes\Application\UseCase\Command\DeleteSubjectCommand;
use App\Classes\Application\UseCase\Command\SubjectDetails;
use App\Classes\Application\UseCase\Command\UpdateSubjectCommand;
use App\Classes\Domain\Exception\ClassesAccessDeniedException;
use App\Classes\Domain\Exception\NoAdvisoryException;
use App\Classes\Domain\Exception\SubjectNotFoundException;
use App\Classes\Presentation\Form\SectionFormType;
use App\Classes\Presentation\Form\SubjectFormType;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Domain\Exception\DomainException;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Subjects pages, shared by the school page (admin, principal) and the
 * Individual-plan teacher's own page. Subclasses decide the scope, routes and layout.
 *
 * @phpstan-import-type SubjectData from \App\Classes\Application\Transformer\SubjectTransformer
 * @phpstan-import-type ParentChoice from SubjectFormType
 *
 * @phpstan-type SubjectForm array{school: array{id: int, name: string}, schoolYear: array{name: string},
 *     subject: ?SubjectData, defaults: ?array{gradeLevel: ?\App\Schools\Domain\Model\GradeLevel, position: int,
 *     track: ?\App\Classes\Domain\Model\ShsTrack, strand: ?string}, parents: list<ParentChoice>,
 *     grades: list<\App\Schools\Domain\Model\GradeLevel>, refusal: ?string}
 */
abstract class AbstractSubjectController extends BusController
{
    public function __construct(
        protected readonly UseCaseBusInterface $useCaseBus,
        protected readonly QueryBusInterface $queryBus,
    ) {
    }

    /** The scope this page works in. */
    abstract protected function scopeKey(): SubjectScopeKey;

    /** The route-name prefix of the page, e.g. "school_subjects". */
    abstract protected function routes(): string;

    /** @return array<string, mixed> parameters every route of the page needs */
    abstract protected function routeParams(): array;

    abstract protected function layout(): string;

    protected function renderList(): Response
    {
        $result = $this->queryBus->handle(new ListSubjectsCommand($this->scopeKey()));
        if (null !== $refusal = $this->refusal($result)) {
            return $this->withoutAdvisory($refusal, true);
        }
        /** @var array{school: array{id: int, name: string}, schoolYear: ?array{name: string},
         *     groups: array<string, list<SubjectData>>, refusal: ?string,
         *     depedGrades: list<\App\Schools\Domain\Model\GradeLevel>, bringBackFrom: ?array{name: string}} $list */
        $list = $result->data();

        return $this->render('@Classes/school_subjects/index.html.twig', $this->pageVars(
            $list['school'],
            $list['schoolYear'],
        ) + [
            'groups' => $list['groups'],
            'refusal' => $list['refusal'],
            'deped_grades' => $list['depedGrades'],
            'bring_back_from' => $list['bringBackFrom'],
        ]);
    }

    protected function handleForm(Request $request, ?int $subjectId): Response
    {
        $result = $this->queryBus->handle(
            new GetSubjectFormCommand($this->scopeKey(), $subjectId, $request->query->getInt('grade', -1)),
        );
        if (null !== $refusal = $this->refusal($result)) {
            return $this->withoutAdvisory($refusal, false);
        }
        /** @var SubjectForm $page */
        $page = $result->data();
        if (null !== $page['refusal']) {
            $this->addFlash('error', $page['refusal']);

            return $this->redirectToList();
        }

        $command = null !== $page['subject']
            ? UpdateSubjectCommand::fromSubject($this->scopeKey(), $page['subject'])
            : $this->newSubject($page);
        $form = $this->createForm(SubjectFormType::class, $command, [
            'grade_levels' => $page['grades'],
            'parents' => $page['parents'],
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $saved = $this->useCaseBus->handle($command);
            if (null !== $failure = $this->refusal($saved)) {
                $this->addFlash('error', $failure->getMessage());
            } else {
                /** @var array{label: string} $subject */
                $subject = $saved->data();
                $this->addFlash(
                    'success',
                    \sprintf(null === $subjectId ? 'Created %s.' : 'Saved %s.', $subject['label']),
                );
            }

            return $this->redirectToList();
        }

        return $this->render('@Classes/school_subjects/form.html.twig', $this->pageVars(
            $page['school'],
            $page['schoolYear'],
        ) + [
            'form' => $form,
            'subject' => ['id' => $subjectId, 'hasComponents' => $page['subject']['hasComponents'] ?? false],
            'strands' => SectionFormType::STRANDS,
        ]);
    }

    protected function handleDelete(int $subjectId): Response
    {
        $result = $this->useCaseBus->handle(new DeleteSubjectCommand($this->scopeKey(), $subjectId));

        return $this->flashResult($result, 'Deleted %s.', 'label');
    }

    protected function handleDepEd(Request $request): Response
    {
        $result = $this->useCaseBus->handle(
            new AddDepEdSubjectsCommand($this->scopeKey(), $request->request->getInt('grade', -1)),
        );

        return $this->flashResult($result, 'Added %d DepEd subjects for %s.', 'count', 'grade');
    }

    protected function handleBringBack(): Response
    {
        $result = $this->useCaseBus->handle(new BringBackSubjectsCommand($this->scopeKey()));

        return $this->flashResult($result, 'Brought back %d subjects from S.Y. %s.', 'count', 'from');
    }

    /**
     * The refusal to show, or null when $result succeeded. A plan with a principal is a 403 for a teacher;
     * a subject, DepEd list or school year that isn't there is a 404.
     */
    protected function refusal(Result $result): ?DomainException
    {
        $failure = $this->domainFailure($result);
        if ($failure instanceof ClassesAccessDeniedException) {
            throw $this->createAccessDeniedException($failure->getMessage());
        }
        if ($failure instanceof SubjectNotFoundException || $failure instanceof SchoolYearNotFoundException) {
            throw $this->createNotFoundException($failure->getMessage());
        }

        return $failure;
    }

    protected function redirectToList(): Response
    {
        return $this->redirectToRoute($this->routes(), $this->routeParams());
    }

    /**
     * A teacher with no section to set subjects up for sees why on the list, and is sent to the list
     * from the other pages.
     */
    private function withoutAdvisory(DomainException $refusal, bool $onList): Response
    {
        if (!$onList || !$refusal instanceof NoAdvisoryException) {
            return $this->redirectToList();
        }

        return $this->render('@Classes/teacher/subjects_need_section.html.twig', [
            'school' => null !== $refusal->schoolName ? ['name' => $refusal->schoolName] : null,
            'school_year' => null !== $refusal->schoolYearName ? ['name' => $refusal->schoolYearName] : null,
        ]);
    }

    /** Flashes the refusal, or $success filled with the result's values under $keys; then back to the list. */
    private function flashResult(Result $result, string $success, string ...$keys): Response
    {
        if (null !== $failure = $this->refusal($result)) {
            $this->addFlash('error', $failure->getMessage());
        } else {
            /** @var array<string, int|string> $data */
            $data = $result->data();
            $this->addFlash('success', \sprintf($success, ...array_map(static fn (string $key) => $data[$key], $keys)));
        }

        return $this->redirectToList();
    }

    /** @param SubjectForm $page */
    private function newSubject(array $page): SubjectDetails
    {
        $command = new CreateSubjectCommand($this->scopeKey());
        $defaults = $page['defaults'];
        if (null !== $defaults) {
            $command->gradeLevel = $defaults['gradeLevel'];
            $command->position = $defaults['position'];
            $command->track = $defaults['track'];
            $command->strand = $defaults['strand'];
        }

        return $command;
    }

    /**
     * @param array{id: int, name: string} $school
     * @param array{name: string}|null     $schoolYear
     *
     * @return array<string, mixed>
     */
    private function pageVars(array $school, ?array $schoolYear): array
    {
        return [
            'school' => $school,
            'school_year' => $schoolYear,
            'layout' => $this->layout(),
            'routes' => $this->routes(),
            'route_params' => $this->routeParams(),
        ];
    }
}
