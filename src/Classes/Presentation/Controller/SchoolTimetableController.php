<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Controller;

use App\Classes\Application\Query\Command\GetSectionTimetableCommand;
use App\Classes\Application\Service\ClassPeriodManager;
use App\Classes\Application\UseCase\Command\PlotClassCommand;
use App\Classes\Application\UseCase\Command\RemoveClassCommand;
use App\Classes\Application\UseCase\Command\SetTeachingLoadCommand;
use App\Classes\Domain\Exception\ClassPeriodNotFoundException;
use App\Classes\Domain\Exception\ScheduleEntryNotFoundException;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Classes\Domain\Exception\SubjectNotFoundException;
use App\Classes\Domain\Exception\TimetableException;
use App\Classes\Presentation\Input\TimetablePlotInput;
use App\Classes\Presentation\Input\TimetableTeacherInput;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Domain\Exception\DomainException;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use App\Schools\Domain\Exception\TeacherNotFoundException;
use App\Schools\Domain\Model\School;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * A section's weekly timetable: the page, and the JSON calls its grid makes. Every change
 * answers with the section's new timetable, or with a 422 refusal.
 */
#[Route('/schools/{id}/sections/{section}/timetable', requirements: ['id' => '\d+', 'section' => '\d+'])]
#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]
class SchoolTimetableController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('', name: 'school_timetable', methods: ['GET'])]
    public function show(Request $request, School $school, int $section): Response
    {
        $timetable = $this->timetable($request, $school, $section);

        return $this->render('@Classes/school_timetable/show.html.twig', [
            'school' => $school,
            'section' => $timetable['section'],
            'state' => $timetable['state'],
            'layout' => $this->isGranted('ROLE_ADMIN')
                ? '@Core/admin/_layout.html.twig'
                : '@Core/principal/_layout.html.twig',
        ]);
    }

    #[Route('/state', name: 'school_timetable_state', methods: ['GET'])]
    public function state(Request $request, School $school, int $section): JsonResponse
    {
        return $this->stateResponse($request, $school, $section);
    }

    #[Route(
        '/loads/{subject}',
        name: 'school_timetable_load',
        requirements: ['subject' => '\d+'],
        methods: ['PUT'],
    )]
    #[IsCsrfTokenValid(
        new Expression('"timetable-" ~ args["section"]'),
        tokenKey: 'X-CSRF-Token',
        tokenSource: IsCsrfTokenValid::SOURCE_HEADER,
    )]
    public function setTeacher(
        Request $request,
        School $school,
        int $section,
        int $subject,
        #[MapRequestPayload] TimetableTeacherInput $input,
    ): JsonResponse {
        $result = $this->useCaseBus->handle(
            new SetTeachingLoadCommand((int) $school->getId(), $section, $subject, $input->teacherId),
        );

        return $this->refused($result) ?? $this->stateResponse($request, $school, $section);
    }

    #[Route('/entries', name: 'school_timetable_plot', methods: ['POST'])]
    #[IsCsrfTokenValid(
        new Expression('"timetable-" ~ args["section"]'),
        tokenKey: 'X-CSRF-Token',
        tokenSource: IsCsrfTokenValid::SOURCE_HEADER,
    )]
    public function plot(
        Request $request,
        School $school,
        int $section,
        #[MapRequestPayload] TimetablePlotInput $input,
    ): JsonResponse {
        $result = $this->useCaseBus->handle(new PlotClassCommand(
            (int) $school->getId(),
            $section,
            (int) $input->subjectId,
            $input->day,
            (int) $input->periodId,
        ));

        return $this->refused($result) ?? $this->stateResponse($request, $school, $section);
    }

    #[Route(
        '/entries/{entry}',
        name: 'school_timetable_remove',
        requirements: ['entry' => '\d+'],
        methods: ['DELETE'],
    )]
    #[IsCsrfTokenValid(
        new Expression('"timetable-" ~ args["section"]'),
        tokenKey: 'X-CSRF-Token',
        tokenSource: IsCsrfTokenValid::SOURCE_HEADER,
    )]
    public function remove(Request $request, School $school, int $section, int $entry): JsonResponse
    {
        $result = $this->useCaseBus->handle(new RemoveClassCommand((int) $school->getId(), $section, $entry));

        return $this->refused($result) ?? $this->stateResponse($request, $school, $section);
    }

    /** @return array{section: array{id: ?int, label: string, schoolYear: array{name: string}}, state: array<string, mixed>} */
    private function timetable(Request $request, School $school, int $section): array
    {
        $result = $this->queryBus->handle(
            new GetSectionTimetableCommand((int) $school->getId(), $section, self::term($request)),
        );
        $this->notFoundOnFailure($result);
        /** @var array{section: array{id: ?int, label: string, schoolYear: array{name: string}}, state: array<string, mixed>} $timetable */
        $timetable = $result->data();

        return $timetable;
    }

    /** The ?term= asked for, or null when it is missing or not a number. */
    private static function term(Request $request): ?int
    {
        $term = $request->query->filter('term', null, \FILTER_VALIDATE_INT, ['flags' => \FILTER_NULL_ON_FAILURE]);

        return \is_int($term) ? $term : null;
    }

    private function stateResponse(Request $request, School $school, int $section): JsonResponse
    {
        $state = $this->timetable($request, $school, $section)['state'];
        $state['busy'] = (object) $state['busy']; // always a JSON object, even when empty

        return new JsonResponse($state); // not $this->json(): the serializer would turn the empty object back into []
    }

    /** The 422 answer to a refused change, or null when it was saved. Anything not found is a 404. */
    private function refused(Result $result): ?JsonResponse
    {
        if ($result->exception() instanceof UniqueConstraintViolationException) {
            // another request saved the same slot first
            return new JsonResponse(['message' => ClassPeriodManager::BUSY], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $failure = $this->notFoundOnFailure($result);
        if (null === $failure) {
            return null;
        }

        $body = ['message' => $failure->getMessage()];
        if ($failure instanceof TimetableException && [] !== $failure->getClashes()) {
            $body['clashes'] = $failure->getClashes();
        }

        return new JsonResponse($body, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function notFoundOnFailure(Result $result): ?DomainException
    {
        $failure = $this->domainFailure($result);
        if (
            $failure instanceof SectionNotFoundException
            || $failure instanceof SubjectNotFoundException
            || $failure instanceof ClassPeriodNotFoundException
            || $failure instanceof TeacherNotFoundException
            || $failure instanceof ScheduleEntryNotFoundException
            || $failure instanceof SchoolYearNotFoundException
        ) {
            throw $this->createNotFoundException($failure->getMessage());
        }

        return $failure;
    }
}
