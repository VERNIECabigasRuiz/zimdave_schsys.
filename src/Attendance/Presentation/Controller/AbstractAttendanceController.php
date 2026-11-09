<?php

declare(strict_types=1);

namespace App\Attendance\Presentation\Controller;

use App\Access\Domain\Exception\AccountNotFoundException;
use App\Attendance\Application\Query\Command\GetAttendanceMonthCommand;
use App\Attendance\Application\Query\Command\GetSf2FileCommand;
use App\Attendance\Domain\Exception\AttendanceAccessDeniedException;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Exception\SchoolYearNotFoundException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * The attendance pages shared by the adviser's class and the school pages: a section's month
 * grid with its SF2 figures, and the SF2 download. Subclasses decide the routes and who may write.
 */
abstract class AbstractAttendanceController extends BusController
{
    public function __construct(
        protected readonly UseCaseBusInterface $useCaseBus,
        protected readonly QueryBusInterface $queryBus,
    ) {
    }

    /** The 1st of the month "Y-m" names, or null for the current month. */
    protected function monthOf(?string $month): ?\DateTimeImmutable
    {
        if (null === $month) {
            return null;
        }

        return \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01') ?: throw $this->createNotFoundException();
    }

    /**
     * @param array{layout: string, month_route: string, route_params: array<string, mixed>, sf2_route: string,
     *     day_route: ?string} $routes
     * @param array<string, mixed> $page the month query's data
     * @param array<string, mixed> $vars
     */
    protected function renderMonth(array $page, array $routes, array $vars = []): Response
    {
        return $this->render('@Attendance/attendance/month.html.twig', $routes + $vars + [
            'section' => $page['section'],
            'report' => $page['report'],
            'today' => $page['today'],
            'previous' => $page['previous'],
            'next' => $page['next'],
        ]);
    }

    /** @return array<string, mixed> the month page of the adviser's section ($schoolId null) or $sectionId */
    protected function monthPage(int $actorId, ?int $schoolId, ?int $sectionId, ?\DateTimeImmutable $month): array
    {
        /** @var array<string, mixed> $page */
        $page = $this->ask(new GetAttendanceMonthCommand($actorId, $schoolId, $sectionId, $month));

        return $page;
    }

    protected function downloadSf2(int $actorId, ?int $schoolId, ?int $sectionId, \DateTimeImmutable $month): Response
    {
        /** @var array{fileName: string, content: string} $file */
        $file = $this->ask(new GetSf2FileCommand($actorId, $schoolId, $sectionId, $month));
        // section names may have an Ñ; old browsers get underscores
        $fallback = (string) preg_replace('/[^\x20-\x7E]/', '_', $file['fileName']);

        return new Response($file['content'], Response::HTTP_OK, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
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

    /** The message of a refused change, or null when it was saved; not found is a 404, not allowed a 403. */
    protected function refusal(Result $result): ?string
    {
        $failure = $this->domainFailure($result);
        if (
            $failure instanceof SectionNotFoundException
            || $failure instanceof SchoolNotFoundException
            || $failure instanceof SchoolYearNotFoundException
            || $failure instanceof AccountNotFoundException
        ) {
            throw $this->createNotFoundException($failure->getMessage());
        }
        if ($failure instanceof AttendanceAccessDeniedException) {
            throw $this->createAccessDeniedException($failure->getMessage());
        }

        return $failure?->getMessage();
    }
}
