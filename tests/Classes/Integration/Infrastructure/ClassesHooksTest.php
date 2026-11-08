<?php

declare(strict_types=1);

namespace App\Tests\Classes\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Classes\Domain\Service\Usage\SectionLearnersInterface;
use App\Classes\Domain\Service\Usage\SubjectRecordsInterface;
use App\Entity\Grade;
use App\Entity\GradeChangeRequest;
use App\Learners\Domain\Model\TransferRequest;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Service\Assignment\TeacherDepartureInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The hooks Classes implements for Schools, and the legacy implementations of the Classes hooks. */
final class ClassesHooksTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
    }

    public function testCountsLoadsAndClearsTheAdvisory(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $teacher = $this->plainUser('rt-teacher', User::ROLE_TEACHER);
        $rizal = $this->newSection($school, $sy, GradeLevel::Grade7, 'Rizal')->setAdviser($teacher);
        $subject = (new Subject($school, $sy))->setGradeLevel(GradeLevel::Grade7)->setCode('MATH7')
            ->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($subject);
        $this->em->persist(new TeachingLoad($rizal, $subject, $teacher));
        $this->em->flush();
        $departures = static::getContainer()->get(TeacherDepartureInterface::class);

        // Execute
        $loads = $departures->countLoads($teacher, $sy);
        $cleared = $departures->clearAdvisory($teacher, $sy);
        $this->em->flush();
        $again = $departures->clearAdvisory($teacher, $sy);

        // Assert
        self::assertSame(1, $loads);
        self::assertSame($rizal->getLabel(), $cleared);
        self::assertNull($rizal->getAdviser());
        self::assertNull($again);
    }

    public function testCountsTheLearnersAndPendingTransfersOfSections(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $adviser = $this->plainUser('rt-adviser', User::ROLE_TEACHER);
        $rizal = $this->newSection($school, $sy, GradeLevel::Grade7, 'Rizal');
        $mabini = $this->newSection($school, $sy, GradeLevel::Grade7, 'Mabini');
        $this->enroll($this->learner('120000000001', 'Abad', 'Ana'), $sy, $rizal, $adviser);
        $leaving = $this->enroll($this->learner('120000000002', 'Bautista', 'Ben'), $sy, $mabini, $adviser);
        $this->em->persist(new TransferRequest($leaving, $rizal, $adviser, new \DateTimeImmutable('2026-10-16')));
        $this->em->flush();
        $learners = static::getContainer()->get(SectionLearnersInterface::class);

        // Execute
        $enrolled = $learners->countEnrolled($rizal);
        $pending = $learners->countPendingTransfersInto($rizal);
        $bySection = $learners->countEnrolledBySection($school, $sy);

        // Assert
        self::assertSame(1, $enrolled);
        self::assertSame(1, $pending);
        self::assertSame([(int) $rizal->getId() => 1, (int) $mabini->getId() => 1], $bySection);
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
}
