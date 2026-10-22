<?php

namespace App\Service;

use App\Entity\Subject;

/**
 * The codes and grade/semester/name pairs a school year already uses, so copied or
 * preset subjects never break the uniqueness rules of `UniqueSubject`.
 */
final class TakenSubjects
{
    /** @var array<string, true> */
    private array $codes = [];

    /** @var array<string, true> */
    private array $names = [];

    /** @param iterable<Subject> $subjects */
    public function __construct(iterable $subjects)
    {
        foreach ($subjects as $subject) {
            $this->add($subject);
        }
    }

    public function has(Subject $subject): bool
    {
        return isset($this->codes[mb_strtoupper($subject->getCode())]) || isset($this->names[self::nameKey($subject)]);
    }

    public function add(Subject $subject): void
    {
        $this->codes[mb_strtoupper($subject->getCode())] = true;
        $this->names[self::nameKey($subject)] = true;
    }

    private static function nameKey(Subject $subject): string
    {
        return $subject->getGradeLevel()?->value.'|'.$subject->getSemester().'|'.mb_strtolower($subject->getName());
    }
}
