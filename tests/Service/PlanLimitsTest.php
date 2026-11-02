<?php

namespace App\Tests\Service;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Entity\GradeLevel;
use App\Entity\Plan;
use App\Entity\Section;
use App\Repository\EnrollmentRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Service\PlanLimitException;
use App\Service\PlanLimits;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class PlanLimitsTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private PlanLimits $limits;
    private SchoolYear $sy;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-16');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        // Built by hand: the container only keeps it once a page uses it (the teachers page does)
        $this->limits = new PlanLimits(static::getContainer()->get(SchoolAssignmentRepository::class), static::getContainer()->get(EnrollmentRepository::class), new Clock());
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
    }

    private function addTeachers(School $school, int $count, ?SchoolYear $sy = null, bool $active = true): void
    {
        static $next = 0;
        for ($i = 0; $i < $count; ++$i) {
            $this->assign($school, $sy ?? $this->sy, $this->plainUser('teacher'.++$next, User::ROLE_TEACHER, $active), User::ROLE_TEACHER);
        }
    }

    private function refusal(School $school): ?string
    {
        try {
            $this->limits->assertCanAddTeacher($school, $this->sy);
        } catch (PlanLimitException $e) {
            self::assertSame($e->getMessage(), $this->limits->canAddTeacher($school, $this->sy));

            return $e->getMessage();
        }
        self::assertNull($this->limits->canAddTeacher($school, $this->sy));

        return null;
    }

    public function testAllowsUnderLimit(): void
    {
        $school = $this->school('300001', 'Pagsabungan NHS');
        $this->addTeachers($school, 14);

        self::assertSame(14, $this->limits->countTeachers($school, $this->sy));
        self::assertNull($this->refusal($school));
    }

    public function testRefusesAtLimit(): void
    {
        $school = $this->school('300001', 'Pagsabungan NHS');
        $this->addTeachers($school, 15);

        self::assertSame('The Basic plan allows 15 teacher(s) (15 used). Contact the administrator to upgrade.', $this->refusal($school));
    }

    public function testSeatsMultiply(): void
    {
        $school = $this->school('300001', 'Solo School', plan: 'Individual', seats: 2);
        $this->addTeachers($school, 1);
        self::assertNull($this->refusal($school));

        $this->addTeachers($school, 1);
        self::assertSame('The Individual plan allows 2 teacher(s) (2 used). Contact the administrator to upgrade.', $this->refusal($school));
    }

    public function testInactiveTeachersDoNotCount(): void
    {
        $school = $this->school('300001', 'Solo School', plan: 'Individual');
        $this->addTeachers($school, 1, active: false);

        self::assertSame(0, $this->limits->countTeachers($school, $this->sy));
        self::assertNull($this->refusal($school));
    }

    public function testOtherSchoolYearAndSchoolDoNotCount(): void
    {
        $school = $this->school('300001', 'Solo School', plan: 'Individual');
        $other = $this->school('300002', 'Other School', plan: 'Individual');
        $this->addTeachers($school, 1, $this->schoolYear(2025, current: false));
        $this->addTeachers($other, 1);

        self::assertSame(0, $this->limits->countTeachers($school, $this->sy));
        self::assertNull($this->refusal($school));
    }

    public function testExpiredRefused(): void
    {
        $school = $this->school('300001', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2026-10-15'));

        self::assertSame('The plan of Pagsabungan NHS expired on Oct 15, 2026. Contact the administrator to renew.', $this->refusal($school));
    }

    public function testValidOnExpiryDay(): void
    {
        $school = $this->school('300001', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2026-10-16'));

        self::assertNull($this->refusal($school));
    }

    public function testNoExpiryAllowed(): void
    {
        self::assertNull($this->refusal($this->school('300001', 'Pagsabungan NHS')));
    }

    public function testInactiveSchoolRefused(): void
    {
        $school = $this->school('300001', 'Pagsabungan NHS', active: false);

        self::assertSame('This school is inactive.', $this->refusal($school));
    }

    private function studentRefusal(School $school, Section $section): ?string
    {
        try {
            $this->limits->assertCanAddStudent($school, $this->sy, $section);
        } catch (PlanLimitException $e) {
            self::assertSame($e->getMessage(), $this->limits->canAddStudent($school, $this->sy, $section));

            return $e->getMessage();
        }
        self::assertNull($this->limits->canAddStudent($school, $this->sy, $section));

        return null;
    }

    private function addLearners(School $school, Section $section, int $count): void
    {
        static $next = 100000;
        $by = $this->plainUser('enroller'.++$next, User::ROLE_PRINCIPAL);
        for ($i = 0; $i < $count; ++$i) {
            $this->enroll($this->learner((string) ++$next, 'Cruz', 'Juan'), $this->sy, $section, $by);
        }
    }

    public function testStudentLimit(): void
    {
        $school = $this->school('300001', 'Pagsabungan NHS', plan: $this->limitedPlan('Test Two Seats', 2)->getName());
        $section = $this->newSection($school, $this->sy, GradeLevel::Grade7, 'Rizal');
        $this->addLearners($school, $section, 1);
        self::assertNull($this->studentRefusal($school, $section));

        $this->addLearners($school, $section, 1);
        self::assertSame(2, $this->limits->countStudents($school, $this->sy));
        self::assertSame('The Test Two Seats plan allows 2 student(s) (2 used). Contact the administrator to upgrade.', $this->studentRefusal($school, $section));
    }

    public function testSectionFullOnPlanWithoutPrincipal(): void
    {
        $solo = (new Plan())->setName('Solo Test')->setTeachersPerSeat(1)->setStudentsPerSeat(2)->setIncludesPrincipal(false);
        $this->em->persist($solo);
        $this->em->flush();
        $school = $this->school('300001', 'Solo School', plan: 'Solo Test', seats: 2);
        $section = $this->newSection($school, $this->sy, GradeLevel::Grade7, 'Rizal');
        $this->addLearners($school, $section, 2);

        self::assertSame('Grade 7 – Rizal is full (2 learners).', $this->studentRefusal($school, $section));

        $other = $this->newSection($school, $this->sy, GradeLevel::Grade7, 'Bonifacio');
        self::assertNull($this->studentRefusal($school, $other));

        $solo->setIncludesPrincipal(true);
        $this->em->flush();
        self::assertNull($this->studentRefusal($school, $section));
    }

    public function testSectionFullSingular(): void
    {
        $solo = (new Plan())->setName('Solo Test')->setTeachersPerSeat(1)->setStudentsPerSeat(1)->setIncludesPrincipal(false);
        $this->em->persist($solo);
        $this->em->flush();
        $school = $this->school('300001', 'Solo School', plan: 'Solo Test', seats: 2);
        $section = $this->newSection($school, $this->sy, GradeLevel::Grade7, 'Rizal');
        $this->addLearners($school, $section, 1);

        self::assertSame('Grade 7 – Rizal is full (1 learner).', $this->studentRefusal($school, $section));
    }

    public function testBlockedSchoolRefusesStudents(): void
    {
        $school = $this->school('300001', 'Pagsabungan NHS', active: false);
        $section = $this->newSection($school, $this->sy, GradeLevel::Grade7, 'Rizal');

        self::assertSame('This school is inactive.', $this->studentRefusal($school, $section));
    }
}