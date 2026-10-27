<?php

namespace App\Tests\Service;

use App\Entity\ClassPeriod;
use App\Entity\GradeLevel;
use App\Entity\ScheduleEntry;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Entity\Weekday;
use App\Service\AssignmentException;
use App\Service\ClassPeriodManager;
use App\Service\InUseException;
use App\Service\SchoolTeacherManager;
use App\Service\SectionManager;
use App\Service\SubjectManager;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class InUseProtectionTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private ClassPeriod $p1;
    private Section $rizal;
    private User $santos;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-16');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->p1 = $this->classPeriod($this->school, $this->sy, '07:30', '08:30');
        $this->rizal = (new Section($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setName('Rizal');
        $this->em->persist($this->rizal);
        $this->santos = $this->plainUser('santos', User::ROLE_TEACHER)->setFullName('Maria Santos');
        $this->assign($this->school, $this->sy, $this->santos, User::ROLE_TEACHER);
        $this->em->flush();
    }

    private function subject(string $code, string $name, ?Subject $parent = null): Subject
    {
        $subject = (new Subject($this->school, $this->sy))
            ->setGradeLevel(GradeLevel::Grade7)->setCode($code)->setName($name)->setPosition(1)
            ->setMeetingsPerWeek($parent || 'MAPEH7' !== $code ? 5 : null)->setParent($parent);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function load(Section $section, Subject $subject): TeachingLoad
    {
        $load = new TeachingLoad($section, $subject, $this->santos);
        $this->em->persist($load);
        $this->em->flush();

        return $load;
    }

    private function refused(string $class, string $message, callable $change): void
    {
        try {
            $change();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame($message, $e->getMessage());

            return;
        }
        self::fail('Expected '.$class.': '.$message);
    }

    public function testPeriodInUse(): void
    {
        $manager = static::getContainer()->get(ClassPeriodManager::class);
        $load = $this->load($this->rizal, $this->subject('MATH7', 'Mathematics'));
        $this->em->persist(new ScheduleEntry($load, Weekday::Monday, $this->p1));
        $this->em->flush();
        $copy = 'Period 1 has classes in 1 section. Remove them first.';

        $this->refused(InUseException::class, $copy, fn () => $manager->delete($this->p1));
        self::assertNotNull($this->em->getRepository(ClassPeriod::class)->find($this->p1->getId()));

        $this->p1->setBreakLabel('Recess');
        $this->refused(InUseException::class, $copy, fn () => $manager->save($this->p1));
        self::assertNull($this->p1->getBreakLabel());

        $this->p1->setStartsAt(new \DateTimeImmutable('07:00'))->setEndsAt(new \DateTimeImmutable('08:00'));
        $manager->save($this->p1);
        $this->em->clear();
        self::assertSame('07:00', $this->em->getRepository(ClassPeriod::class)->find($this->p1->getId())->getStartsAt()->format('H:i'));
    }

    public function testSubjectInUse(): void
    {
        $manager = static::getContainer()->get(SubjectManager::class);
        $mapeh = $this->subject('MAPEH7', 'MAPEH');
        $ma = $this->subject('MA7', 'Music and Arts', $mapeh);
        $this->load($this->rizal, $ma);

        $this->refused(InUseException::class, 'MAPEH is loaded in 1 section.', fn () => $manager->delete($mapeh));
        $this->refused(InUseException::class, 'Music and Arts is loaded in 1 section.', fn () => $manager->delete($ma));
        self::assertNotNull($this->em->getRepository(Subject::class)->find($mapeh->getId()));
    }

    public function testSubjectNotLoadedCanBeDeleted(): void
    {
        $subject = $this->subject('MATH7', 'Mathematics');
        static::getContainer()->get(SubjectManager::class)->delete($subject);
        self::assertSame(0, $this->em->getRepository(Subject::class)->count([]));
    }

    public function testSectionInUse(): void
    {
        $manager = static::getContainer()->get(SectionManager::class);
        $this->load($this->rizal, $this->subject('MATH7', 'Mathematics'));

        $this->refused(InUseException::class, 'Grade 7 – Rizal has a timetable. Remove its classes and teachers first.', fn () => $manager->delete($this->rizal));
        self::assertSame(1, $this->em->getRepository(Section::class)->count([]));
    }

    public function testTeacherWithLoadsCannotBeRemovedOrMoved(): void
    {
        $manager = static::getContainer()->get(SchoolTeacherManager::class);
        $other = $this->school('309999', 'Other NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->load($this->rizal, $this->subject('MATH7', 'Mathematics'));
        $this->load($this->rizal, $this->subject('SCI7', 'Science'));
        $copy = 'Maria Santos still has 2 loads this school year.';

        $this->refused(AssignmentException::class, $copy, fn () => $manager->removeFromSchool($this->sy, $this->santos));
        $this->refused(AssignmentException::class, $copy, fn () => $manager->assignToSchool($other, $this->sy, $this->santos));
    }
}
