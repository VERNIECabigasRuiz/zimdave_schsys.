<?php

declare(strict_types=1);

namespace App\Tests\Schools\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Service\Assignment\TeacherDepartureInterface;
use App\Schools\Domain\Service\Plan\EnrolledLearnersInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The legacy implementations of the Schools hooks for learners and a teacher leaving. */
final class SchoolsHooksTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
    }

    public function testCountsEnrolledLearnersPerSchoolAndSection(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $adviser = $this->plainUser('rt-adviser', User::ROLE_TEACHER);
        $rizal = $this->newSection($school, $sy, GradeLevel::Grade7, 'Rizal');
        $mabini = $this->newSection($school, $sy, GradeLevel::Grade7, 'Mabini');
        $this->enroll($this->learner('120000000001', 'Abad', 'Ana'), $sy, $rizal, $adviser);
        $this->enroll($this->learner('120000000002', 'Bautista', 'Ben'), $sy, $rizal, $adviser);
        $this->enroll($this->learner('120000000003', 'Cruz', 'Cara'), $sy, $mabini, $adviser);
        $learners = static::getContainer()->get(EnrolledLearnersInterface::class);

        // Execute
        $inSchool = $learners->countForSchool($school, $sy);
        $inRizal = $learners->countForSection((int) $rizal->getId());

        // Assert
        self::assertSame(3, $inSchool);
        self::assertSame(2, $inRizal);
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
}
