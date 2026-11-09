<?php

declare(strict_types=1);

namespace App\Tests\Schools\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Service\Plan\EnrolledLearnersInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The legacy implementation of the Schools hook for learners. */
final class SchoolsHooksTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    protected EntityManagerInterface $em;

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
}
