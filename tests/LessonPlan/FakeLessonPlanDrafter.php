<?php

namespace App\Tests\LessonPlan;

use App\Entity\LessonPlan;
use App\LessonPlan\AiDraftFailed;
use App\LessonPlan\DraftRequest;
use App\LessonPlan\DraftResult;
use App\LessonPlan\LessonPlanDrafter;

/** Stands in for the AI in tests: a fixed draft sized to the request, or a failure. Never calls the API. */
class FakeLessonPlanDrafter implements LessonPlanDrafter
{
    /** Set to make the next drafts fail with this reason. */
    public ?string $fail = null;
    public int $calls = 0;
    public ?DraftRequest $lastRequest = null;

    public function draft(DraftRequest $request): DraftResult
    {
        ++$this->calls;
        $this->lastRequest = $request;
        if (null !== $this->fail) {
            throw new AiDraftFailed($this->fail);
        }

        $days = [];
        foreach ($request->dates as $i => $date) {
            $day = [];
            foreach (LessonPlan::DAY_FIELDS as $field) {
                $day[$field] = [\sprintf('%s day %d point 1', $field, $i + 1), \sprintf('%s day %d point 2', $field, $i + 1)];
            }
            $days[] = $day;
        }

        return new DraftResult(['Mathematics 8 Learner\'s Module', 'DepEd LR Portal'], $days, 'fake-model', 1200, 3400);
    }
}
