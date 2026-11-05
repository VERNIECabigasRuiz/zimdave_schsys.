<?php

namespace App\LessonPlan;

/** Drafts the AI parts of a week's ILAW lesson plan. */
interface LessonPlanDrafter
{
    /** @throws AiDraftFailed with a reason the teacher can read */
    public function draft(DraftRequest $request): DraftResult;
}
