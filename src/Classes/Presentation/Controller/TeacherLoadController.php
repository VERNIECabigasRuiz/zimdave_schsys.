<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Classes\Application\Query\Command\GetTeacherLoadCommand;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Exception\TeacherNotFoundException;
use App\Schools\Domain\Model\School;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** A teacher's subjects and weekly timetable: for the school's principal, or the teacher's own. */
class TeacherLoadController extends BusController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    #[Route(
        '/schools/{id}/teachers/{teacher}/load',
        name: 'school_teacher_load',
        requirements: ['id' => '\d+', 'teacher' => '\d+'],
        methods: ['GET'],
    )]
    #[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]
    public function forPrincipal(Request $request, School $school, int $teacher, #[CurrentUser] User $user): Response
    {
        $result = $this->queryBus->handle(
            new GetTeacherLoadCommand($teacher, (int) $school->getId(), self::term($request)),
        );
        if ($this->domainFailure($result) instanceof TeacherNotFoundException) {
            throw $this->createNotFoundException($result->exception()?->getMessage() ?? '');
        }

        return $this->page(
            $result,
            $this->isGranted('ROLE_ADMIN') ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig',
            $teacher === $user->getId(),
        );
    }

    #[Route('/teacher/load', name: 'teacher_load', methods: ['GET'])]
    #[IsGranted(User::ROLE_TEACHER)]
    public function mine(Request $request, #[CurrentUser] User $user): Response
    {
        $result = $this->queryBus->handle(
            new GetTeacherLoadCommand((int) $user->getId(), null, self::term($request)),
        );
        $this->domainFailure($result);

        return $this->page($result, 'teacher/_layout.html.twig', true);
    }

    /** The ?term= asked for, or null when it is missing or not a number. */
    private static function term(Request $request): ?int
    {
        $term = $request->query->filter('term', null, \FILTER_VALIDATE_INT, ['flags' => \FILTER_NULL_ON_FAILURE]);

        return \is_int($term) ? $term : null;
    }

    private function page(Result $result, string $layout, bool $self): Response
    {
        /** @var array{teacher: array{id: int, fullName: string}, school: ?array{id: int, name: string},
         *     schoolYear: ?array{name: string}, week: ?array<string, mixed>, term: int, hasSemesters: bool} $load */
        $load = $result->data();

        return $this->render('@Classes/teacher_load/show.html.twig', [
            'layout' => $layout,
            'teacher' => $load['teacher'],
            'school' => $load['school'],
            'school_year' => $load['schoolYear'],
            'week' => $load['week'],
            'term' => $load['term'],
            'has_semesters' => $load['hasSemesters'],
            'self' => $self,
        ]);
    }
}
