<?php

namespace App\LessonPlan;

/** What the AI is told about the week it drafts. */
final readonly class DraftRequest
{
    /**
     * @param list<string> $competencies one per line as the teacher typed them
     * @param list<string> $dates        the school days to draft (Y-m-d), holidays left out
     */
    public function __construct(
        public string $level,
        public string $grade,
        public string $subject,
        public string $section,
        public string $topic,
        public array $competencies,
        public ?string $classNotes,
        public array $dates,
        public bool $filipino,
    ) {
    }
}
