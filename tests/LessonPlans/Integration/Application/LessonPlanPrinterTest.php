<?php

declare(strict_types=1);

namespace App\Tests\LessonPlans\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\LessonPlans\Application\Service\LessonPlanPrinter;
use App\LessonPlans\Domain\Model\LessonPlan;
use App\LessonPlans\Domain\Service\Signatory\SignatoryPositionsInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class LessonPlanPrinterTest extends KernelTestCase
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

    public function testThePrintoutNamesBothSignatoriesAndTheBullets(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, current: true);
        $school = $this->school('303196', 'Pagsabungan NHS');
        $tess = $this->plainUser('tess', User::ROLE_TEACHER)->setFullName('Tess Reyes');
        $rene = $this->plainUser('rene', User::ROLE_PRINCIPAL)->setFullName('Rene Petancio');
        $this->assign($school, $sy, $rene);
        $load = $this->load($school, $sy, $tess, 'A/B 1%');
        $plan = (new LessonPlan($load, new \DateTimeImmutable('2026-11-30'), new \DateTimeImmutable()))
            ->setCompetencies("M8AL-Ia-1\n\n  M8AL-Ia-2  ")
            ->setReferences(null);

        // Execute
        $printout = $this->printer(['tess' => 'Teacher III'])->printout($plan);

        // Assert
        self::assertSame(['name' => 'TESS REYES', 'position' => 'Teacher III'], $printout->teacher);
        self::assertSame(['name' => 'RENE PETANCIO', 'position' => 'School Principal'], $printout->principal);
        self::assertSame(['M8AL-Ia-1', 'M8AL-Ia-2'], $printout->competencies);
        self::assertSame([], $printout->references);
        self::assertSame($school->getId(), $printout->school->getId());
        self::assertSame('ILAW - Mathematics - A-B 1- - 2026-11-30.pdf', $printout->fileName());
    }

    public function testWithoutAPrincipalOrACurrentYearOnlyTheTeacherSigns(): void
    {
        // Initialize
        $sy = $this->schoolYear(2026, current: true);
        $school = $this->school('303196', 'Pagsabungan NHS');
        $tess = $this->plainUser('tess', User::ROLE_TEACHER)->setFullName('Tess Reyes');
        $plan = new LessonPlan(
            $this->load($school, $sy, $tess),
            new \DateTimeImmutable('2026-11-30'),
            new \DateTimeImmutable(),
        );

        // Execute
        $noPrincipal = $this->printer([])->printout($plan);
        $sy->setCurrent(false);
        $this->em->flush();
        $noYear = $this->printer([])->printout($plan);

        // Assert
        self::assertSame(['name' => 'TESS REYES', 'position' => 'Teacher'], $noPrincipal->teacher);
        self::assertNull($noPrincipal->principal);
        self::assertNull($noYear->principal);
    }

    /** @param array<string, string> $positions by username */
    private function printer(array $positions): LessonPlanPrinter
    {
        $signatories = $this->createStub(SignatoryPositionsInterface::class);
        $signatories->method('positionOf')->willReturnCallback(
            static fn (User $user) => $positions[$user->getUsername()] ?? null,
        );

        return new LessonPlanPrinter(static::getContainer()->get(SchoolDirectoryInterface::class), $signatories);
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
