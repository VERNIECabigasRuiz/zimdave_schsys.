<?php

declare(strict_types=1);

namespace App\Attendance\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Attendance\Application\Query\Command\GetAttendanceOverviewCommand;
use App\Attendance\Application\UseCase\Command\ReopenSf2Command;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\School;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The school's attendance pages for the principal and administrators: today and the month across
 * sections, a section's month with its SF2, and reopening a submitted month.
 */
#[Route('/schools/{id}', requirements: ['id' => '\d+', 'section' => '\d+', 'month' => '\d{4}-\d{2}'])]
#[IsGranted(SchoolDirectoryInterface::MANAGE, 'school')]
class SchoolAttendanceController extends AbstractAttendanceController
{
    #[Route('/attendance', name: 'school_attendance', methods: ['GET'])]
    public function overview(
        School $school,
        #[CurrentUser] User $user,
        #[MapQueryParameter(options: ['regexp' => '/^\d{4}-\d{2}$/'], flags: \FILTER_NULL_ON_FAILURE)]
        ?string $month = null,
    ): Response {
        /** @var array<string, mixed> $page */
        $page = $this->ask(new GetAttendanceOverviewCommand(
            (int) $user->getId(),
            (int) $school->getId(),
            $this->monthOf($month),
        ));

        return $this->render('@Attendance/attendance/overview.html.twig', [
            'layout' => $this->layout(),
            'school' => $page['school'],
            'school_year' => $page['schoolYear'],
            'month' => $page['month'],
            'months' => $page['months'],
            'today' => $page['today'],
            'by_grade' => $page['byGrade'],
            'follow_ups' => $page['followUps'],
        ]);
    }

    #[Route('/sections/{section}/attendance/{month}', name: 'school_attendance_section', methods: ['GET'])]
    public function section(School $school, int $section, #[CurrentUser] User $user, ?string $month = null): Response
    {
        $page = $this->monthPage((int) $user->getId(), (int) $school->getId(), $section, $this->monthOf($month));
        $shown = $page['month'];
        \assert($shown instanceof \DateTimeImmutable);

        return $this->renderMonth($page, [
            'layout' => $this->layout(),
            'month_route' => 'school_attendance_section',
            'route_params' => ['id' => $school->getId(), 'section' => $section],
            'sf2_route' => 'school_attendance_sf2',
            'day_route' => null,
        ], [
            'reopen_url' => true === $page['canReopen']
                ? $this->generateUrl(
                    'school_attendance_reopen',
                    ['id' => $school->getId(), 'section' => $section, 'month' => $shown->format('Y-m')],
                )
                : null,
        ]);
    }

    #[Route('/sections/{section}/attendance/{month}/sf2', name: 'school_attendance_sf2', methods: ['GET'])]
    public function sf2(School $school, int $section, #[CurrentUser] User $user, string $month): Response
    {
        $first = $this->monthOf($month);
        \assert(null !== $first);

        return $this->downloadSf2((int) $user->getId(), (int) $school->getId(), $section, $first);
    }

    #[Route('/sections/{section}/attendance/{month}/reopen', name: 'school_attendance_reopen', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"reopen-" ~ args["section"]'), tokenKey: '_token')]
    public function reopen(School $school, int $section, #[CurrentUser] User $user, string $month): Response
    {
        $first = $this->monthOf($month);
        \assert(null !== $first);

        $reopened = $this->useCaseBus->handle(
            new ReopenSf2Command((int) $user->getId(), (int) $school->getId(), $section, $first),
        );
        if (null !== $refusal = $this->refusal($reopened)) {
            $this->addFlash('error', $refusal);
        } else {
            $this->addFlash(
                'success',
                \sprintf('%s reopened; the adviser can change it and submit again.', $first->format('F Y')),
            );
        }

        return $this->redirectToRoute(
            'school_attendance_section',
            ['id' => $school->getId(), 'section' => $section, 'month' => $first->format('Y-m')],
        );
    }

    private function layout(): string
    {
        return $this->isGranted(User::ROLE_ADMIN) ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig';
    }
}
