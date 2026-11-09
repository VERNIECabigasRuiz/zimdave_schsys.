<?php

declare(strict_types=1);

namespace App\Grading\Application\Service;

use App\Grading\Domain\Model\ClassGradeStatus;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;

/**
 * How far a class (a subject of a section) is with its grades for a period; $listed counts the learners
 * who can be graded.
 */
final readonly class ClassProgress
{
    public function __construct(
        public Section $section,
        public Subject $subject,
        public ?string $teacherName,
        public int $graded,
        public int $listed,
        public ClassGradeStatus $status,
    ) {
    }

    /** Every learner who can be graded has a grade. */
    public function isComplete(): bool
    {
        return $this->graded === $this->listed;
    }
}
