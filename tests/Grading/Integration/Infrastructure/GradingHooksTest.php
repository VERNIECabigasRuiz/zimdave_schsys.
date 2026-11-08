<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Infrastructure;

use App\Classes\Domain\Service\Usage\SubjectRecordsInterface;
use App\Grading\Domain\Model\GradeChangeRequest;
use App\Learners\Domain\Service\Usage\LearnerGradesInterface;
use App\Access\Domain\Model\User;
use App\Grading\Domain\Model\Grade;
use App\Grading\Domain\Model\GradeLock;
use App\Classes\Domain\Model\Subject;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\GradingSystem;
use App\Schools\Domain\Model\SchoolYear;
use App\Schools\Domain\Service\SchoolYear\GradingPeriodUsageInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The hooks Grading implements for Schools, Classes and Learners. */
final class GradingHooksTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private GradingPeriodUsageInterface $usage;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->usage = static::getContainer()->get(GradingPeriodUsageInterface::class);
        $this->clearSchoolData();
    }

    public function testCountsOpenSchoolsPerPeriod(): void
    {
        // Initialize
        $sy = $this->quarters();
        $this->openGrading($this->school('300001', 'Alpha NHS'), $this->period($sy, 2));
        $this->openGrading($this->school('300002', 'Beta NHS'), $this->period($sy, 2));

        // Execute
        $second = $this->usage->countSchoolsOpen($this->period($sy, 2));
        $first = $this->usage->countSchoolsOpen($this->period($sy, 1));

        // Assert
        self::assertSame(2, $second);
        self::assertSame(0, $first);
    }

    public function testKnowsWhetherAPeriodOfTheYearIsOpen(): void
    {
        // Initialize
        $open = $this->quarters();
        $closed = $this->schoolYear(2027, false);
        $this->openGrading($this->school('300001', 'Alpha NHS'), $this->period($open, 1));

        // Execute
        $openYear = $this->usage->hasOpenPeriod($open);
        $closedYear = $this->usage->hasOpenPeriod($closed);

        // Assert
        self::assertTrue($openYear);
        self::assertFalse($closedYear);
    }

    public function testAPeriodIsGradedByAGradeOrALock(): void
    {
        // Initialize
        $sy = $this->quarters();
        $school = $this->school('300001', 'Alpha NHS');
        $teacher = $this->plainUser('rt-teacher', User::ROLE_TEACHER);
        $section = $this->newSection($school, $sy, GradeLevel::Grade8, 'Honest');
        $math = (new Subject($school, $sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')
            ->setName('Mathematics')->setPosition(1);
        $math->setMeetingsPerWeek(5);
        $this->em->persist($math);
        $enrollment = $this->enroll($this->learner('120017170001', 'Abad', 'Ana'), $sy, $section, $teacher);
        $graded = new Grade($enrollment, $math, $this->period($sy, 1), 90, $teacher, new \DateTimeImmutable());
        $this->em->persist($graded);
        $this->em->persist(new GradeLock($school, $this->period($sy, 3), $teacher, new \DateTimeImmutable()));
        $this->em->flush();

        // Execute
        $byGrade = $this->usage->isGraded($this->period($sy, 1));
        $none = $this->usage->isGraded($this->period($sy, 2));
        $byLock = $this->usage->isGraded($this->period($sy, 3));

        // Assert
        self::assertTrue($byGrade);
        self::assertFalse($none);
        self::assertTrue($byLock);
    }

    public function testCountsTheGradesAndChangeRequestsOfASubject(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $teacher = $this->plainUser('rt-teacher', User::ROLE_TEACHER);
        $rizal = $this->newSection($school, $sy, GradeLevel::Grade7, 'Rizal');
        $math = (new Subject($school, $sy))->setGradeLevel(GradeLevel::Grade7)->setCode('MATH7')
            ->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $term = new GradingPeriod($sy, 1);
        $this->em->persist($math);
        $this->em->persist($term);
        $enrollment = $this->enroll($this->learner('120000000001', 'Abad', 'Ana'), $sy, $rizal, $teacher);
        $at = new \DateTimeImmutable('2026-10-16');
        $this->em->persist(new Grade($enrollment, $math, $term, 85, $teacher, $at));
        $this->em->persist(new GradeChangeRequest($rizal, $math, $term, $teacher, 'Encoding error on a quiz', $at));
        $this->em->flush();
        $records = static::getContainer()->get(SubjectRecordsInterface::class);

        // Execute
        $grades = $records->countGrades($math);
        $requests = $records->countGradeChangeRequests($math);

        // Assert
        self::assertSame(1, $grades);
        self::assertSame(1, $requests);
    }

    public function testCountsTheGradesOfAnEnrollment(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $teacher = $this->plainUser('rt-teacher', User::ROLE_TEACHER);
        $rizal = $this->newSection($school, $sy, GradeLevel::Grade7, 'Rizal');
        $math = (new Subject($school, $sy))->setGradeLevel(GradeLevel::Grade7)->setCode('MATH7')
            ->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $term = new GradingPeriod($sy, 1);
        $this->em->persist($math);
        $this->em->persist($term);
        $graded = $this->enroll($this->learner('120000000001', 'Abad', 'Ana'), $sy, $rizal, $teacher);
        $ungraded = $this->enroll($this->learner('120000000002', 'Bautista', 'Ben'), $sy, $rizal, $teacher);
        $this->em->persist(new Grade($graded, $math, $term, 85, $teacher, new \DateTimeImmutable('2026-10-16')));
        $this->em->flush();
        $grades = static::getContainer()->get(LearnerGradesInterface::class);

        // Execute
        $withGrades = $grades->countForEnrollment($graded);
        $withoutGrades = $grades->countForEnrollment($ungraded);

        // Assert
        self::assertSame(1, $withGrades);
        self::assertSame(0, $withoutGrades);
    }

    private function quarters(): SchoolYear
    {
        $sy = $this->schoolYear(2026, true)->setGradingSystem(GradingSystem::Quarters);
        $sy->rebuildGradingPeriods();
        $this->em->flush();

        return $sy;
    }

    private function period(SchoolYear $sy, int $number): GradingPeriod
    {
        return $sy->getGradingPeriod($number) ?? throw new \LogicException('Missing period ' . $number);
    }
}
