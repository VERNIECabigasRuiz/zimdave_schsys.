<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Controller;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Core\Presentation\Controller\BusController;
use App\Schools\Application\Query\Command\GetSchoolCalendarCommand;
use App\Schools\Application\UseCase\Command\AddDaysOffCommand;
use App\Schools\Application\UseCase\Command\RemoveDayOffCommand;
use App\Schools\Domain\Exception\DayOffNotFoundException;
use App\Schools\Domain\Model\School;
use App\Schools\Presentation\Form\DayOffFormType;
use App\Schools\Presentation\Security\SchoolVoter;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The school calendar's days off this S.Y. — admin for any school, principal for their own.
 *
 * @phpstan-import-type SchoolData from \App\Schools\Application\Transformer\SchoolTransformer
 * @phpstan-import-type SchoolYearData from \App\Schools\Application\Transformer\SchoolYearTransformer
 */
#[Route('/schools/{id}/calendar', requirements: ['id' => '\d+', 'dayOff' => '\d+'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolCalendarController extends BusController
{
    public function __construct(
        private readonly UseCaseBusInterface $useCaseBus,
        private readonly QueryBusInterface $queryBus,
    ) {
    }

    #[Route('', name: 'school_calendar', methods: ['GET', 'POST'])]
    public function index(Request $request, School $school, #[CurrentUser] User $user): Response
    {
        $schoolId = (int) $school->getId();
        $result = $this->queryBus->handle(new GetSchoolCalendarCommand($schoolId));
        if (null !== $this->domainFailure($result)) {
            throw $this->createNotFoundException('No current school year.');
        }
        /** @var array{school: SchoolData, schoolYear: SchoolYearData, daysOff: list<mixed>} $calendar */
        $calendar = $result->data();

        $command = new AddDaysOffCommand($schoolId, (int) $user->getId());
        $form = $this->createForm(DayOffFormType::class, $command);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $added = $this->useCaseBus->handle($command);
            $failure = $this->domainFailure($added);
            if (null !== $failure) {
                $this->addFlash('error', $failure->getMessage());
            } else {
                /** @var array{added: int, sectionsWithAttendance: int} $counts */
                $counts = $added->data();
                $this->flashAdded($counts['added'], $counts['sectionsWithAttendance']);

                return $this->redirectToRoute('school_calendar', ['id' => $schoolId]);
            }
        }

        return $this->render('@Schools/attendance/calendar.html.twig', [
            'layout' => $this->layout(),
            'school' => $calendar['school'],
            'school_year' => $calendar['schoolYear'],
            'days_off' => $calendar['daysOff'],
            'form' => $form,
        ]);
    }

    #[Route('/{dayOff}/remove', name: 'school_calendar_remove', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"day-off-" ~ args["dayOff"]'), tokenKey: '_token')]
    public function remove(School $school, int $dayOff): Response
    {
        $result = $this->useCaseBus->handle(new RemoveDayOffCommand((int) $school->getId(), $dayOff));
        if ($this->domainFailure($result) instanceof DayOffNotFoundException) {
            throw $this->createNotFoundException();
        }
        /** @var array{date: \DateTimeImmutable} $removed */
        $removed = $result->data();
        $this->addFlash('success', \sprintf('%s is a school day again.', $removed['date']->format('l, F j')));

        return $this->redirectToRoute('school_calendar', ['id' => $school->getId()]);
    }

    private function flashAdded(int $added, int $sectionsWithAttendance): void
    {
        $this->addFlash(
            'success',
            0 === $added ? 'Those days are already off.' : \sprintf(
                '%d %s off added.',
                $added,
                1 === $added ? 'day' : 'days',
            ),
        );
        if ($sectionsWithAttendance > 0) {
            $this->addFlash('warning', \sprintf(
                '%d %s already took attendance on those days; those days no longer count.',
                $sectionsWithAttendance,
                1 === $sectionsWithAttendance ? 'section' : 'sections',
            ));
        }
    }

    private function layout(): string
    {
        return $this->isGranted(User::ROLE_ADMIN) ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig';
    }
}
