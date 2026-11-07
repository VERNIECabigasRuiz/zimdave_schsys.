<?php

namespace App\Controller;

use App\Dto\TimetablePlotInput;
use App\Dto\TimetableTeacherInput;
use App\Classes\Domain\Model\ClassPeriod;
use App\Classes\Domain\Model\ScheduleEntry;
use App\Schools\Domain\Model\School;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Weekday;
use App\Classes\Domain\Model\Repository\ClassPeriodRepositoryInterface;
use App\Classes\Domain\Model\Repository\ScheduleEntryRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Classes\Domain\Model\Repository\SectionRepositoryInterface;
use App\Classes\Domain\Model\Repository\SubjectRepositoryInterface;
use App\Access\Domain\Model\Repository\UserRepositoryInterface;
use App\Schools\Presentation\Security\SchoolVoter;
use App\Schools\Domain\Exception\PlanLimitException;
use App\Service\TimetableException;
use App\Service\TimetableManager;
use App\Service\TimetableQuery;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The timetable of one section: the page, its state, and the writes the page makes.
 * Writes answer with the fresh state, or 422 {message, clashes?} when refused.
 */
#[Route('/schools/{id}/sections/{section}/timetable', requirements: ['id' => '\d+', 'section' => '\d+'])]
#[IsGranted(SchoolVoter::MANAGE, 'school')]
class SchoolTimetableController extends AbstractController
{
    public function __construct(
        private readonly SectionRepositoryInterface $sections,
        private readonly SubjectRepositoryInterface $subjects,
        private readonly ClassPeriodRepositoryInterface $periods,
        private readonly ScheduleEntryRepositoryInterface $entries,
        private readonly UserRepositoryInterface $users,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly TimetableManager $manager,
        private readonly TimetableQuery $query,
    ) {
    }

    #[Route('', name: 'school_timetable', methods: ['GET'])]
    public function show(Request $request, School $school, int $section): Response
    {
        $section = $this->requireSection($school, $section);
        $state = $this->query->sectionState($section, $this->term($request, $section));

        return $this->render('school_timetable/show.html.twig', [
            'school' => $school,
            'section' => $section,
            'state' => $state,
            'layout' => $this->isGranted('ROLE_ADMIN') ? 'admin/_layout.html.twig' : 'principal/_layout.html.twig',
        ]);
    }

    #[Route('/state', name: 'school_timetable_state', methods: ['GET'])]
    public function state(Request $request, School $school, int $section): JsonResponse
    {
        $section = $this->requireSection($school, $section);

        return $this->stateResponse($section, $this->term($request, $section));
    }

    #[Route('/loads/{subject}', name: 'school_timetable_load', requirements: ['subject' => '\d+'], methods: ['PUT'])]
    #[IsCsrfTokenValid(new Expression('"timetable-" ~ args["section"]'), tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function setTeacher(Request $request, School $school, int $section, int $subject, #[MapRequestPayload] TimetableTeacherInput $input): JsonResponse
    {
        $section = $this->requireSection($school, $section);
        $subject = $this->requireSubject($school, $subject);
        $teacher = null === $input->teacherId ? null : $this->requireTeacher($school, $section, $input->teacherId);

        try {
            $this->manager->setTeacher($section, $subject, $teacher);
        } catch (TimetableException|PlanLimitException $e) {
            return $this->refused($e);
        }

        return $this->stateResponse($section, $this->term($request, $section));
    }

    #[Route('/entries', name: 'school_timetable_plot', methods: ['POST'])]
    #[IsCsrfTokenValid(new Expression('"timetable-" ~ args["section"]'), tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function plot(Request $request, School $school, int $section, #[MapRequestPayload] TimetablePlotInput $input): JsonResponse
    {
        $section = $this->requireSection($school, $section);
        $subject = $this->requireSubject($school, (int) $input->subjectId);
        $period = $this->periods->findById((int) $input->periodId);
        if (!$period instanceof ClassPeriod || $period->getSchool() !== $school || $period->getSchoolYear() !== $section->getSchoolYear()) {
            throw $this->createNotFoundException('This class period is not in the school this school year.');
        }

        try {
            $this->manager->plot($section, $subject, Weekday::from($input->day), $period);
        } catch (TimetableException|PlanLimitException $e) {
            return $this->refused($e);
        }

        return $this->stateResponse($section, $this->term($request, $section));
    }

    #[Route('/entries/{entry}', name: 'school_timetable_remove', requirements: ['entry' => '\d+'], methods: ['DELETE'])]
    #[IsCsrfTokenValid(new Expression('"timetable-" ~ args["section"]'), tokenKey: 'X-CSRF-Token', tokenSource: IsCsrfTokenValid::SOURCE_HEADER)]
    public function remove(Request $request, School $school, int $section, int $entry): JsonResponse
    {
        $section = $this->requireSection($school, $section);
        $entry = $this->entries->findById($entry);
        if (!$entry instanceof ScheduleEntry || $entry->getSection() !== $section) {
            throw $this->createNotFoundException('This class is not in the section.');
        }

        try {
            $this->manager->remove($entry);
        } catch (TimetableException|PlanLimitException $e) {
            return $this->refused($e);
        }

        return $this->stateResponse($section, $this->term($request, $section));
    }

    private function stateResponse(Section $section, int $term): JsonResponse
    {
        $state = $this->query->sectionState($section, $term);
        $state['busy'] = (object) $state['busy']; // always a JSON object, even when empty

        return new JsonResponse($state); // not $this->json(): the serializer would turn the empty object back into []
    }

    private function refused(TimetableException|PlanLimitException $e): JsonResponse
    {
        $body = ['message' => $e->getMessage()];
        if ($e instanceof TimetableException && [] !== $e->getClashes()) {
            $body['clashes'] = $e->getClashes();
        }

        return new JsonResponse($body, Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    private function term(Request $request, Section $section): int
    {
        $terms = $this->query->termsFor($section);
        $term = $request->query->getInt('term', $terms[0]);

        return \in_array($term, $terms, true) ? $term : $terms[0];
    }

    /** A section of this school in the current school year. */
    private function requireSection(School $school, int $id): Section
    {
        $section = $this->sections->findById($id);
        if (null === $section || $section->getSchool() !== $school || $section->getSchoolYear() !== $this->schoolYears->findCurrent()) {
            throw $this->createNotFoundException('This section is not in the school this school year.');
        }

        return $section;
    }

    private function requireSubject(School $school, int $id): Subject
    {
        $subject = $this->subjects->findById($id);
        if (null === $subject || $subject->getSchool() !== $school || $subject->getSchoolYear() !== $this->schoolYears->findCurrent()) {
            throw $this->createNotFoundException('This subject is not in the school this school year.');
        }

        return $subject;
    }

    /** A teacher of this school this school year. */
    private function requireTeacher(School $school, Section $section, int $id): User
    {
        $teacher = $this->users->findById($id);
        if (null === $teacher || $this->assignments->findSchoolOf($teacher, $section->getSchoolYear(), User::ROLE_TEACHER) !== $school) {
            throw $this->createNotFoundException('This teacher is not in the school this school year.');
        }

        return $teacher;
    }
}
