<?php

namespace App\LessonPlan;

use App\Entity\AiDraft;
use App\Entity\LessonPlan;
use App\Entity\School;
use App\Entity\User;
use App\Repository\AiDraftRepository;
use App\Service\SchoolClock;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Fills a lesson plan's AI cells, within the school plan's monthly AI drafts per teacher. Only a successful draft
 * counts; the teacher's topic, competencies and notes are never changed.
 */
class LessonPlanAi
{
    public function __construct(
        private readonly LessonPlanDrafter $drafter,
        private readonly AiDraftRepository $drafts,
        private readonly EntityManagerInterface $entityManager,
        private readonly SchoolClock $clock,
    ) {
    }

    public function limit(School $school): int
    {
        return $school->getPlan()?->getAiDraftsPerTeacherMonthly() ?? 0;
    }

    /** Drafts left for the teacher this calendar month. */
    public function remaining(User $teacher, School $school): int
    {
        $monthStart = $this->clock->now()->modify('first day of this month')->setTime(0, 0);

        return max(0, $this->limit($school) - $this->drafts->countSince($teacher, $monthStart));
    }

    /** @throws AiDraftFailed nothing on the plan is changed and nothing is counted */
    public function draft(LessonPlan $plan, User $teacher): void
    {
        $load = $plan->getLoad();
        $school = $load->getSection()->getSchool();
        if (0 === $this->remaining($teacher, $school)) {
            throw new AiDraftFailed('no AI drafts left this month');
        }

        $days = $plan->getDays();
        $schoolDays = array_keys(array_filter($days, static fn (array $day) => null === ($day['holiday'] ?? null)));
        if ([] === $schoolDays) {
            throw new AiDraftFailed('this week has no school days to draft');
        }

        $subject = $load->getSubject();
        $result = $this->drafter->draft(new DraftRequest(
            level: $school->getType()?->label() ?? 'School',
            grade: $load->getSection()->getGradeLevel()?->label() ?? '',
            subject: $subject->getName(),
            section: $load->getSection()->getName(),
            topic: $plan->getTopic(),
            competencies: LessonPlan::bullets($plan->getCompetencies()),
            classNotes: $plan->getClassNotes(),
            dates: array_map(static fn (int $i) => (string) $days[$i]['date'], $schoolDays),
            filipino: 1 === preg_match('/filipino|araling\s+panlipunan/i', $subject->getName().' '.$subject->getCode()),
        ));

        foreach ($schoolDays as $n => $i) {
            foreach (LessonPlan::DAY_FIELDS as $field) {
                $days[$i][$field] = implode("\n", $result->days[$n][$field]);
            }
        }
        $now = $this->clock->now();
        $plan->setDays($days)
            ->setReferences(implode("\n", $result->references))
            ->setAiDeclaration(\sprintf(
                'AI (Claude, %s) was used to draft the learning objectives, learner context, learning experiences, resources, integration, assessment, extended learning, reflections and references from the topic and competencies given by the teacher. The teacher reviewed and edited the draft.',
                $result->model,
            ))
            ->setDraftedAt($now)
            ->touch($now);
        $this->entityManager->persist(new AiDraft($teacher, $school, $result->model, $result->inputTokens, $result->outputTokens, $now));
        $this->entityManager->flush();
    }
}
