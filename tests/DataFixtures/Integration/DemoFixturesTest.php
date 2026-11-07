<?php

declare(strict_types=1);

namespace App\Tests\DataFixtures\Integration;

use App\DataFixtures\DemoFixtures;
use App\Schools\Domain\Model\Division;
use App\Entity\Enrollment;
use App\Entity\Grade;
use App\Entity\LessonPlan;
use App\Entity\RollCall;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Service\Plan\PlanLimits;
use App\Tests\SchoolFixturesTrait;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DemoFixturesTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
    }

    public function testLoadsDemoData(): void
    {
        // Execute
        $this->loadDemo();

        // Assert
        self::assertSame(1, $this->em->getRepository(Division::class)->count(['name' => 'Mandaue City']));
        self::assertSame('Basic', $this->demoSchool(DemoFixtures::BASIC_SCHOOL_ID)->getPlan()?->getName());
        self::assertSame('Individual', $this->demoSchool(DemoFixtures::INDIVIDUAL_SCHOOL_ID)->getPlan()?->getName());

        $schoolYear = $this->currentSchoolYear();
        $today = new \DateTimeImmutable('today');
        self::assertLessThanOrEqual($today, $schoolYear->getStartDate());
        self::assertGreaterThanOrEqual($today, $schoolYear->getEndDate());
        self::assertCount($schoolYear->getGradingSystem()->periodCount(), $schoolYear->getGradingPeriods());

        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([DemoFixtures::PRINCIPAL, ...DemoFixtures::TEACHERS, DemoFixtures::SOLO_TEACHER] as $username) {
            $user = $this->em->getRepository(User::class)->findOneBy(['username' => $username]);
            self::assertInstanceOf(User::class, $user, $username);
            self::assertFalse($user->mustChangePassword(), $username);
            self::assertTrue($hasher->isPasswordValid($user, DemoFixtures::PASSWORD), $username);
        }

        $basic = $this->demoSchool(DemoFixtures::BASIC_SCHOOL_ID);
        $sections = $this->em->getRepository(Section::class)->findBy(['school' => $basic]);
        self::assertCount(3, $sections);
        foreach ($sections as $section) {
            self::assertSame(25, $this->em->getRepository(Enrollment::class)->count(['section' => $section]));
        }
        self::assertGreaterThan(0, $this->em->getRepository(RollCall::class)->count([]));
        self::assertGreaterThan(0, $this->em->getRepository(Grade::class)->count([]));
        self::assertGreaterThan(0, $this->em->getRepository(LessonPlan::class)->count([]));
    }

    public function testStaysWithinPlanLimits(): void
    {
        // Initialize
        $this->loadDemo();
        $limits = static::getContainer()->get(PlanLimits::class);
        $basic = $this->demoSchool(DemoFixtures::BASIC_SCHOOL_ID);
        $individual = $this->demoSchool(DemoFixtures::INDIVIDUAL_SCHOOL_ID);

        // Execute
        $basicBlocked = $limits->blockedReason($basic);
        $individualBlocked = $limits->blockedReason($individual);
        $individualFull = $limits->canAddTeacher($individual, $this->currentSchoolYear());

        // Assert
        self::assertNull($basicBlocked);
        self::assertNull($individualBlocked);
        self::assertNotNull($individualFull);
    }

    public function testRefusesToLoadTwice(): void
    {
        // Expect
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageIsOrContains('Demo data is already loaded. Run make fixture-load first.');

        // Initialize
        $this->loadDemo();

        // Execute
        $this->loadDemo();
    }

    private function loadDemo(): void
    {
        $fixture = static::getContainer()->get(DemoFixtures::class);
        (new ORMExecutor($this->em))->execute([$fixture], append: true);
    }

    private function demoSchool(string $schoolId): School
    {
        $school = $this->em->getRepository(School::class)->findOneBy(['schoolId' => $schoolId]);
        self::assertInstanceOf(School::class, $school);

        return $school;
    }

    private function currentSchoolYear(): SchoolYear
    {
        $schoolYear = static::getContainer()->get(SchoolYearRepositoryInterface::class)->findCurrent();
        self::assertInstanceOf(SchoolYear::class, $schoolYear);

        return $schoolYear;
    }
}
