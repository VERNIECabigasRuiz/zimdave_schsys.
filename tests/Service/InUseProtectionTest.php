<?php

namespace App\Tests\Service;

use App\Entity\ClassPeriod;
use App\Entity\GradeLevel;
use App\Entity\ScheduleEntry;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\ShsTrack;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\TransferRequest;
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
use Symfony\Component\Lock\LockFactory;

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

    public function testPeriodWaitsForTimetableChanges(): void
    {
        $manager = static::getContainer()->get(ClassPeriodManager::class);
        $lock = static::getContainer()->get(LockFactory::class)->createLock('school-timetable-'.$this->school->getId());
        self::assertTrue($lock->acquire());
        $copy = 'Another change is being saved. Please try again.';

        try {
            $this->p1->setBreakLabel('Recess');
            $this->refused(InUseException::class, $copy, fn () => $manager->save($this->p1));
            self::assertNull($this->p1->getBreakLabel());
            $this->refused(InUseException::class, $copy, fn () => $manager->delete($this->p1));
            $this->refused(InUseException::class, $copy, fn () => $manager->save(new ClassPeriod($this->school, $this->sy)));
        } finally {
            $lock->release();
        }
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(ClassPeriod::class)->count([]));

        // Free again once the other change is saved
        $manager->delete($this->em->find(ClassPeriod::class, $this->p1->getId()));
        self::assertSame(0, $this->em->getRepository(ClassPeriod::class)->count([]));
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

    public function testLoadedSubjectKeepsGradeSemesterAndParent(): void
    {
        $manager = static::getContainer()->get(SubjectManager::class);
        $math = $this->subject('MATH7', 'Mathematics');
        $mapeh = $this->subject('MAPEH7', 'MAPEH');
        $this->subject('MA7', 'Music and Arts', $mapeh);
        $this->load($this->rizal, $math);
        $copy = 'Mathematics is loaded in 1 section. Clear its teachers first.';

        $math->setGradeLevel(GradeLevel::Grade8);
        $this->refused(InUseException::class, $copy, fn () => $manager->save($math));
        self::assertSame(GradeLevel::Grade7, $math->getGradeLevel());

        $math->setSemester(1);
        $this->refused(InUseException::class, $copy, fn () => $manager->save($math));
        self::assertNull($math->getSemester());

        $math->setParent($mapeh);
        $this->refused(InUseException::class, $copy, fn () => $manager->save($math));
        self::assertNull($math->getParent());

        // Other changes to a loaded subject still save
        $math->setName('Math')->setMeetingsPerWeek(4);
        $manager->save($math);
        $this->em->clear();
        self::assertSame('Math', $this->em->getRepository(Subject::class)->find($math->getId())->getName());
    }

    public function testNoNewComponentUnderLoadedSubject(): void
    {
        $manager = static::getContainer()->get(SubjectManager::class);
        $science = $this->subject('SCI7', 'Science');
        $this->load($this->rizal, $science);
        $bonifacio = (new Section($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setName('Bonifacio');
        $this->em->persist($bonifacio);
        $this->load($bonifacio, $science);

        $component = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setCode('BIO7')->setName('Biology')->setPosition(1)->setMeetingsPerWeek(5)->setParent($science);
        $this->refused(InUseException::class, 'Science is loaded in 2 sections. Clear its teachers first.', fn () => $manager->save($component));
        $this->em->clear();
        self::assertNull($this->em->getRepository(Subject::class)->findOneBy(['code' => 'BIO7']));
    }

    public function testComponentCanBeAddedWhenOnlySiblingsAreLoaded(): void
    {
        $manager = static::getContainer()->get(SubjectManager::class);
        $mapeh = $this->subject('MAPEH7', 'MAPEH');
        $this->load($this->rizal, $this->subject('MA7', 'Music and Arts', $mapeh));

        $manager->save((new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setCode('H7')->setName('Health')->setPosition(2)->setMeetingsPerWeek(5)->setParent($mapeh));
        self::assertNotNull($this->em->getRepository(Subject::class)->findOneBy(['code' => 'H7']));
    }

    public function testSectionWithTimetableKeepsGradeTrackAndStrand(): void
    {
        $manager = static::getContainer()->get(SectionManager::class);
        $this->load($this->rizal, $this->subject('MATH7', 'Mathematics'));
        $copy = 'Grade 7 – Rizal has a timetable. Remove its classes and teachers first.';

        $this->rizal->setGradeLevel(GradeLevel::Grade8);
        $this->refused(InUseException::class, $copy, fn () => $manager->save($this->rizal));
        self::assertSame(GradeLevel::Grade7, $this->rizal->getGradeLevel());

        $this->rizal->setStrand('STEM');
        $this->refused(InUseException::class, $copy, fn () => $manager->save($this->rizal));
        self::assertNull($this->rizal->getStrand());

        $this->rizal->setTrack(ShsTrack::Academic);
        $this->refused(InUseException::class, $copy, fn () => $manager->save($this->rizal));
        self::assertNull($this->rizal->getTrack());

        // Renaming or changing the adviser still saves
        $this->rizal->setName('Jose Rizal')->setAdviser($this->santos);
        $manager->save($this->rizal);
        $this->em->clear();
        self::assertSame('Jose Rizal', $this->em->getRepository(Section::class)->find($this->rizal->getId())->getName());
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

    public function testSectionWithLearnersCannotBeDeleted(): void
    {
        $manager = static::getContainer()->get(SectionManager::class);
        $this->enroll($this->learner('100000000001', 'Cruz', 'Ana'), $this->sy, $this->rizal, $this->santos);

        $this->refused(InUseException::class, 'Grade 7 – Rizal has 1 learner. Move or unenroll them first.', fn () => $manager->delete($this->rizal));
        self::assertSame(1, $this->em->getRepository(Section::class)->count([]));

        $this->enroll($this->learner('100000000002', 'Reyes', 'Ben'), $this->sy, $this->rizal, $this->santos);
        $this->refused(InUseException::class, 'Grade 7 – Rizal has 2 learners. Move or unenroll them first.', fn () => $manager->delete($this->rizal));
    }

    public function testSectionWithPendingTransfersCannotBeDeleted(): void
    {
        $manager = static::getContainer()->get(SectionManager::class);
        $bonifacio = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Bonifacio');
        $enrollment = $this->enroll($this->learner('100000000001', 'Cruz', 'Ana'), $this->sy, $bonifacio, $this->santos);
        $this->em->persist(new TransferRequest($enrollment, $this->rizal, $this->santos, new \DateTimeImmutable('2026-10-16')));
        $this->em->flush();

        $this->refused(InUseException::class, 'Grade 7 – Rizal has 1 pending transfer.', fn () => $manager->delete($this->rizal));
        self::assertSame(1, $this->em->getRepository(TransferRequest::class)->count([]));
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
