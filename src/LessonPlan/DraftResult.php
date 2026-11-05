<?php

namespace App\LessonPlan;

/** The AI's draft: references, and per school day each ILAW cell as a list of bullet lines. */
final readonly class DraftResult
{
    /**
     * @param list<string>                      $references
     * @param list<array<string, list<string>>> $days       in the order of the request's dates
     */
    public function __construct(
        public array $references,
        public array $days,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
    ) {
    }
}
