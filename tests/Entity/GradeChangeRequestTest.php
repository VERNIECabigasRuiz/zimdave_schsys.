<?php

namespace App\Tests\Entity;

use App\Entity\Enrollment;
use App\Entity\GradeChangeRequest;
use App\Entity\GradeChangeStatus;
use App\Entity\GradeLevel;
use App\Entity\Learner;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class GradeChangeRequestTest extends TestCase
{
    private User $teacher;
    private User $principal;
    private GradeChangeRequest $request;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $school = new School();
        $sy = new SchoolYear();
        $sy->setName('2025-2026')->setStartDate(new \DateTimeImmutable('2025-06-01'))->setEndDate(new \DateTimeImmutable('2026-04-30'));
        $sy->rebuildGradingPeriods();
        $section = (new Section($school, $sy))->setGradeLevel(GradeLevel::Grade8)->setName('Honest');
        $subject = (new Subject($school, $sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')->setName('Mathematics')->setPosition(1);
        $this->teacher = (new User())->setUsername('dave');
        $this->principal = (new User())->setUsername('rosa');
        $this->now = new \DateTimeImmutable('2025-08-22 10:00:00');
        $this->request = new GradeChangeRequest($section, $subject, $sy->getGradingPeriod(1), $this->teacher, 'Encoding mistake in the score sheet', $this->now);
    }

    public function testNewRequestIsPending(): void
    {
        $this->assertSame(GradeChangeStatus::Pending, $this->request->getStatus());
        $this->assertTrue($this->request->isPending());
        $this->assertSame($this->teacher, $this->request->getRequestedBy());
        $this->assertSame('Encoding mistake in the score sheet', $this->request->getReason());
        $this->assertSame($this->now, $this->request->getCreatedAt());
        $this->assertNull($this->request->getDecidedBy());
        $this->assertNull($this->request->getDecidedAt());
        $this->assertNull($this->request->getDecisionNote());
        $this->assertCount(0, $this->request->getLines());
        $this->assertSame('Pending', GradeChangeStatus::Pending->label());
    }

    public function testAddLineKeepsOldAndNewGrade(): void
    {
        $section = $this->request->getSection();
        $enrollment = new Enrollment(new Learner(new User(), $this->now), $section->getSchoolYear(), $section, $this->teacher, $this->now);
        $this->request->addLine($enrollment, null, 85);
        $line = $this->request->getLines()->first();
        $this->assertSame($enrollment, $line->getEnrollment());
        $this->assertNull($line->getFromValue());
        $this->assertSame(85, $line->getToValue());
    }

    public function testApprove(): void
    {
        $at = $this->now->modify('+1 day');
        $this->request->approve($this->principal, $at);
        $this->assertSame(GradeChangeStatus::Approved, $this->request->getStatus());
        $this->assertFalse($this->request->isPending());
        $this->assertSame($this->principal, $this->request->getDecidedBy());
        $this->assertSame($at, $this->request->getDecidedAt());
    }

    public function testDeclineStoresNote(): void
    {
        $this->request->decline($this->principal, $this->now, 'Please attach the quiz papers');
        $this->assertSame(GradeChangeStatus::Declined, $this->request->getStatus());
        $this->assertSame('Please attach the quiz papers', $this->request->getDecisionNote());
        $this->assertSame($this->principal, $this->request->getDecidedBy());
    }

    public function testWithdrawSetsDecidedAtOnly(): void
    {
        $this->request->withdraw($this->now);
        $this->assertSame(GradeChangeStatus::Withdrawn, $this->request->getStatus());
        $this->assertSame($this->now, $this->request->getDecidedAt());
        $this->assertNull($this->request->getDecidedBy());
    }

    public function testSecondTransitionThrows(): void
    {
        $this->request->approve($this->principal, $this->now);
        foreach ([
            fn () => $this->request->approve($this->principal, $this->now),
            fn () => $this->request->decline($this->principal, $this->now, 'late note'),
            fn () => $this->request->withdraw($this->now),
        ] as $again) {
            try {
                $again();
                $this->fail('Expected LogicException');
            } catch (\LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(GradeChangeStatus::Approved, $this->request->getStatus());
    }
}
