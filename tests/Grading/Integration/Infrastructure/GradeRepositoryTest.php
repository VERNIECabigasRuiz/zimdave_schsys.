<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Subject;
use App\Grading\Domain\Model\Grade;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\GradingPeriod;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GradeRepositoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testAFreshReadSeesWhatAnotherRequestSavedMeanwhile(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, true);
        $school = $this->school('300001', 'Alpha NHS');
        $teacher = $this->plainUser('gr-teacher', User::ROLE_TEACHER);
        $rizal = $this->newSection($school, $sy, GradeLevel::Grade7, 'Rizal');
        $math = (new Subject($school, $sy))->setGradeLevel(GradeLevel::Grade7)->setCode('MATH7')
            ->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $term = new GradingPeriod($sy, 1);
        $this->em->persist($math);
        $this->em->persist($term);
        $enrollment = $this->enroll($this->learner('120000000001', 'Abad', 'Ana'), $sy, $rizal, $teacher);
        $grade = new Grade($enrollment, $math, $term, 85, $teacher, new \DateTimeImmutable('2026-10-16'));
        $this->em->persist($grade);
        $this->em->flush();
        $grades = static::getContainer()->get(GradeRepositoryInterface::class);
        $ids = [(int) $enrollment->getId()];
        $this->em->getConnection()->executeStatement('UPDATE grade SET value = 90 WHERE id = ?', [$grade->getId()]);

        // Execute
        $stale = $grades->findForEnrollments($ids, $math, $term)[(int) $enrollment->getId()]->getValue();
        $fresh = $grades->findFreshForEnrollments($ids, $math, $term)[(int) $enrollment->getId()]->getValue();
        $none = $grades->findFreshForEnrollments([], $math, $term);

        // Assert
        self::assertSame([85, 90], [$stale, $fresh]);
        self::assertSame([], $none);
    }
}
