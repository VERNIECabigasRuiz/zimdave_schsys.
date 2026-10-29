<?php

namespace App\Tests\Entity;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\Learner;
use App\Entity\LearnerMark;
use App\Entity\RollCall;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class RollCallTest extends TestCase
{
    private Section $section;
    private User $adviser;

    protected function setUp(): void
    {
        $sy = (new SchoolYear())->setName('2025-2026');
        $this->section = (new Section(new School(), $sy))->setGradeLevel(GradeLevel::Grade8)->setName('Artistic');
        $this->adviser = new User();
    }

    private function enrollment(int $id): Enrollment
    {
        $enrollment = new Enrollment(new Learner(new User(), new \DateTimeImmutable()), $this->section->getSchoolYear(), $this->section, $this->adviser, new \DateTimeImmutable());
        (new \ReflectionProperty(Enrollment::class, 'id'))->setValue($enrollment, $id);

        return $enrollment;
    }

    public function testReplaceMarksAddsChangesAndRemoves(): void
    {
        [$a, $b, $c] = [$this->enrollment(1), $this->enrollment(2), $this->enrollment(3)];
        $rollCall = new RollCall($this->section, new \DateTimeImmutable('2025-08-22 10:30'), $this->adviser, new \DateTimeImmutable('2025-08-22 07:40'));

        self::assertSame('2025-08-22 00:00', $rollCall->getDate()->format('Y-m-d H:i'));

        $rollCall->replaceMarks([1 => [$a, LearnerMark::Absent], 2 => [$b, LearnerMark::Late]]);
        self::assertCount(2, $rollCall->getMarks());
        self::assertSame(LearnerMark::Absent, $rollCall->markFor($a));
        self::assertSame(LearnerMark::Late, $rollCall->markFor($b));
        self::assertNull($rollCall->markFor($c));
        self::assertNull($rollCall->getUpdatedAt());

        $mark = $rollCall->getMarks()->filter(static fn ($m) => $m->getEnrollment() === $b)->first();
        $rollCall->replaceMarks([2 => [$b, LearnerMark::Cutting], 3 => [$c, LearnerMark::Absent]]);
        $rollCall->touch(new \DateTimeImmutable('2025-08-22 09:00'));

        self::assertCount(2, $rollCall->getMarks());
        self::assertNull($rollCall->markFor($a));
        self::assertSame(LearnerMark::Cutting, $rollCall->markFor($b));
        self::assertSame(LearnerMark::Absent, $rollCall->markFor($c));
        // a changed mark is updated in place, not replaced by a new row
        self::assertSame($mark, $rollCall->getMarks()->filter(static fn ($m) => $m->getEnrollment() === $b)->first());
        self::assertSame('2025-08-22 09:00', $rollCall->getUpdatedAt()?->format('Y-m-d H:i'));
    }
}
