<?php

declare(strict_types=1);

namespace App\Tests\LessonPlans\Integration\Infrastructure;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\LessonPlans\Domain\Model\LessonPlan;
use App\LessonPlans\Domain\Model\Repository\LessonPlanRepositoryInterface;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LessonPlanRepositoryTest extends KernelTestCase
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

    public function testAddsFindsAndRemovesAPlan(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, current: true);
        $school = $this->school('303196', 'Pagsabungan NHS');
        $tess = $this->plainUser('tess', User::ROLE_TEACHER);
        $load = $this->load($school, $sy, $tess);
        $repository = static::getContainer()->get(LessonPlanRepositoryInterface::class);
        $plan = new LessonPlan($load, new \DateTimeImmutable('2026-11-23'), new \DateTimeImmutable('2026-11-20 08:00'));

        // Execute
        $repository->add($plan);
        $this->em->flush();
        $id = (int) $plan->getId();
        $foundId = $repository->findById($id)?->getId();
        $ofWeekId = $repository->findOneFor($load, new \DateTimeImmutable('2026-11-23 15:00'))?->getId();
        $repository->remove($plan);
        $this->em->flush();
        $gone = $repository->findById($id);

        // Assert
        self::assertSame($id, $foundId);
        self::assertSame($id, $ofWeekId);
        self::assertNull($gone);
        self::assertNull($repository->findById(-1));
    }

    private function load(
        School $school,
        SchoolYear $schoolYear,
        User $teacher,
        string $section = 'Artistic',
    ): TeachingLoad {
        $subject = (new Subject($school, $schoolYear))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')
            ->setName('Mathematics')->setPosition(1);
        $this->em->persist($subject);
        $load = new TeachingLoad(
            $this->newSection($school, $schoolYear, GradeLevel::Grade8, $section),
            $subject,
            $teacher,
        );
        $this->em->persist($load);
        $this->em->flush();

        return $load;
    }
}
