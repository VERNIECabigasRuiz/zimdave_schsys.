<?php

namespace App\Tests\Classes\Integration\Application;

use App\Classes\Domain\Model\ClassPeriod;
use App\Schools\Domain\Model\GradeLevel;
use App\Classes\Domain\Model\ScheduleEntry;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\ShsTrack;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Learners\Domain\Model\TransferRequest;
use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Weekday;
use App\Classes\Application\Service\ClassPeriodManager;
use App\Classes\Application\Transformer\ClassPeriodTransformer;
use App\Classes\Application\UseCase\Command\CreateClassPeriodCommand;
use App\Classes\Application\UseCase\Command\DeleteClassPeriodCommand;
use App\Classes\Application\UseCase\Command\UpdateClassPeriodCommand;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Classes\Domain\Exception\InUseException;
use App\Classes\Application\Service\SectionManager;
use App\Classes\Application\Service\Subject\SubjectScopeKey;
use App\Classes\Application\Service\SubjectManager;
use App\Classes\Application\Transformer\SubjectTransformer;
use App\Classes\Application\UseCase\Command\UpdateSubjectCommand;
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
        $this->school = $this->school(
            '303456',
            'Pagsabungan NHS',
            planValidUntil: new \DateTimeImmutable('2027-04-30'),
        );
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

    /** An edit of $period that keeps every stored value until the test changes one. */
    private function periodDetails(ClassPeriod $period): UpdateClassPeriodCommand
    {
        return UpdateClassPeriodCommand::fromPeriod(
            (int) $this->school->getId(),
            ClassPeriodTransformer::toArray($period),
        );
    }

    /** An edit of $subject that keeps every stored value until the test changes one. */
    private function details(Subject $subject): UpdateSubjectCommand
    {
        return UpdateSubjectCommand::fromSubject(
            SubjectScopeKey::school((int) $this->school->getId()),
            SubjectTransformer::toArray($subject),
        );
    }

    /** @param class-string<\Throwable> $class */
    private function refused(string $class, string $message, callable $change): void
    {
        try {
            $change();
        } catch (\Throwable $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame($message, $e->getMessage());

            return;
        }
        self::fail('Expected ' . $class . ': ' . $message);
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

        $toBreak = $this->periodDetails($this->p1);
        $toBreak->isBreak = true;
        $toBreak->breakLabel = 'Recess';
        $this->refused(InUseException::class, $copy, fn () => $manager->update($this->p1, $toBreak));
        self::assertNull($this->p1->getBreakLabel());

        $earlier = $this->periodDetails($this->p1);
        $earlier->startsAt = new \DateTimeImmutable('07:00');
        $earlier->endsAt = new \DateTimeImmutable('08:00');
        $manager->update($this->p1, $earlier);
        $this->em->flush();
        $this->em->clear();
        self::assertSame(
            '07:00',
            $this->em->getRepository(ClassPeriod::class)->find($this->p1->getId())?->getStartsAt()?->format('H:i'),
        );
    }

    public function testPeriodWaitsForTimetableChanges(): void
    {
        $bus = static::getContainer()->get(UseCaseBusInterface::class);
        $lock = static::getContainer()->get(LockFactory::class)
            ->createLock('school-timetable-' . $this->school->getId());
        self::assertTrue($lock->acquire());
        $copy = 'Another change is being saved. Please try again.';
        $schoolId = (int) $this->school->getId();
        $toBreak = $this->periodDetails($this->p1);
        $toBreak->isBreak = true;
        $toBreak->breakLabel = 'Recess';
        $new = new CreateClassPeriodCommand($schoolId);
        $new->startsAt = new \DateTimeImmutable('09:00');
        $new->endsAt = new \DateTimeImmutable('10:00');

        try {
            foreach ([$toBreak, new DeleteClassPeriodCommand($schoolId, (int) $this->p1->getId()), $new] as $change) {
                $result = $bus->handle($change);
                self::assertSame(['RESOURCE_BUSY', $copy], [$result->errorCode(), $result->exception()?->getMessage()]);
            }
            self::assertNull($this->p1->getBreakLabel());
        } finally {
            $lock->release();
        }
        $this->em->clear();
        self::assertSame(1, $this->em->getRepository(ClassPeriod::class)->count([]));

        // Free again once the other change is saved
        self::assertTrue($bus->handle(new DeleteClassPeriodCommand($schoolId, (int) $this->p1->getId()))->isSuccess());
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

        $change = $this->details($math);
        $change->gradeLevel = GradeLevel::Grade8;
        $this->refused(InUseException::class, $copy, fn () => $manager->update($math, $change, null));
        self::assertSame(GradeLevel::Grade7, $math->getGradeLevel());

        $change = $this->details($math);
        $change->semester = 1;
        $this->refused(InUseException::class, $copy, fn () => $manager->update($math, $change, null));
        self::assertNull($math->getSemester());

        $this->refused(InUseException::class, $copy, fn () => $manager->update($math, $this->details($math), $mapeh));
        self::assertNull($math->getParent());

        // Other changes to a loaded subject still save
        $change = $this->details($math);
        $change->name = 'Math';
        $change->meetingsPerWeek = 4;
        $manager->update($math, $change, null);
        $this->em->flush();
        $this->em->clear();
        self::assertSame('Math', $this->em->getRepository(Subject::class)->find($math->getId())?->getName());
    }

    public function testASubjectIsNotMovedUnderALoadedSubject(): void
    {
        // Initialize
        $manager = static::getContainer()->get(SubjectManager::class);
        $science = $this->subject('SCI7', 'Science');
        $biology = $this->subject('BIO7', 'Biology');
        $this->load($this->rizal, $science);

        // Execute
        $this->refused(
            InUseException::class,
            'Science is loaded in 1 section. Clear its teachers first.',
            fn () => $manager->update($biology, $this->details($biology), $science),
        );

        // Assert
        self::assertNull($biology->getParent());
    }

    public function testNoNewComponentUnderLoadedSubject(): void
    {
        $manager = static::getContainer()->get(SubjectManager::class);
        $science = $this->subject('SCI7', 'Science');
        $this->load($this->rizal, $science);
        $bonifacio = (new Section($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setName('Bonifacio');
        $this->em->persist($bonifacio);
        $this->load($bonifacio, $science);

        $component = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)
            ->setCode('BIO7')->setName('Biology')->setPosition(1)->setMeetingsPerWeek(5)->setParent($science);
        $this->refused(
            InUseException::class,
            'Science is loaded in 2 sections. Clear its teachers first.',
            fn () => $manager->create($component),
        );
        $this->em->clear();
        self::assertNull($this->em->getRepository(Subject::class)->findOneBy(['code' => 'BIO7']));
    }

    public function testComponentCanBeAddedWhenOnlySiblingsAreLoaded(): void
    {
        $manager = static::getContainer()->get(SubjectManager::class);
        $mapeh = $this->subject('MAPEH7', 'MAPEH');
        $this->load($this->rizal, $this->subject('MA7', 'Music and Arts', $mapeh));

        $manager->create((new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)
            ->setCode('H7')->setName('Health')->setPosition(2)->setMeetingsPerWeek(5)->setParent($mapeh));
        $this->em->flush();
        self::assertNotNull($this->em->getRepository(Subject::class)->findOneBy(['code' => 'H7']));
    }

    public function testSectionWithTimetableKeepsGradeTrackAndStrand(): void
    {
        $manager = static::getContainer()->get(SectionManager::class);
        $this->load($this->rizal, $this->subject('MATH7', 'Mathematics'));
        $copy = 'Grade 7 – Rizal has a timetable. Remove its classes and teachers first.';

        $this->refused(
            InUseException::class,
            $copy,
            fn () => $manager->update($this->rizal, GradeLevel::Grade8, 'Rizal', null, null, null),
        );
        self::assertSame(GradeLevel::Grade7, $this->rizal->getGradeLevel());

        $this->refused(
            InUseException::class,
            $copy,
            fn () => $manager->update($this->rizal, GradeLevel::Grade7, 'Rizal', null, 'STEM', null),
        );
        self::assertNull($this->rizal->getStrand());

        $this->refused(
            InUseException::class,
            $copy,
            fn () => $manager->update($this->rizal, GradeLevel::Grade7, 'Rizal', ShsTrack::Academic, null, null),
        );
        self::assertNull($this->rizal->getTrack());

        // Renaming or changing the adviser still saves
        $manager->update($this->rizal, GradeLevel::Grade7, 'Jose Rizal', null, null, $this->santos);
        $this->em->flush();
        $this->em->clear();
        self::assertSame(
            'Jose Rizal',
            $this->em->getRepository(Section::class)->find($this->rizal->getId())?->getName(),
        );
    }

    public function testSubjectNotLoadedCanBeDeleted(): void
    {
        $subject = $this->subject('MATH7', 'Mathematics');
        static::getContainer()->get(SubjectManager::class)->delete($subject);
        $this->em->flush();
        self::assertSame(0, $this->em->getRepository(Subject::class)->count([]));
    }

    public function testSectionInUse(): void
    {
        $manager = static::getContainer()->get(SectionManager::class);
        $this->load($this->rizal, $this->subject('MATH7', 'Mathematics'));

        $this->refused(
            InUseException::class,
            'Grade 7 – Rizal has a timetable. Remove its classes and teachers first.',
            fn () => $manager->delete($this->rizal),
        );
        self::assertSame(1, $this->em->getRepository(Section::class)->count([]));
    }

    public function testSectionWithLearnersCannotBeDeleted(): void
    {
        $manager = static::getContainer()->get(SectionManager::class);
        $this->enroll($this->learner('100000000001', 'Cruz', 'Ana'), $this->sy, $this->rizal, $this->santos);

        $this->refused(
            InUseException::class,
            'Grade 7 – Rizal has 1 learner. Move or unenroll them first.',
            fn () => $manager->delete($this->rizal),
        );
        self::assertSame(1, $this->em->getRepository(Section::class)->count([]));

        $this->enroll($this->learner('100000000002', 'Reyes', 'Ben'), $this->sy, $this->rizal, $this->santos);
        $this->refused(
            InUseException::class,
            'Grade 7 – Rizal has 2 learners. Move or unenroll them first.',
            fn () => $manager->delete($this->rizal),
        );
    }

    public function testSectionWithPendingTransfersCannotBeDeleted(): void
    {
        $manager = static::getContainer()->get(SectionManager::class);
        $bonifacio = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Bonifacio');
        $enrollment = $this->enroll(
            $this->learner('100000000001', 'Cruz', 'Ana'),
            $this->sy,
            $bonifacio,
            $this->santos,
        );
        $this->em->persist(new TransferRequest(
            $enrollment,
            $this->rizal,
            $this->santos,
            new \DateTimeImmutable('2026-10-16'),
        ));
        $this->em->flush();

        $this->refused(
            InUseException::class,
            'Grade 7 – Rizal has 1 pending transfer.',
            fn () => $manager->delete($this->rizal),
        );
        self::assertSame(1, $this->em->getRepository(TransferRequest::class)->count([]));
    }
}
