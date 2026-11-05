<?php

namespace App\LessonPlan;

/** An AI draft that didn't happen; the reason is shown to the teacher. */
class AiDraftFailed extends \RuntimeException
{
    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct($reason, 0, $previous);
    }
}
