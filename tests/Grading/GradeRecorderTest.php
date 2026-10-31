<?php

namespace App\Tests\Grading;

use App\Entity\ActivityLog;
use App\Entity\Enrollment;
use App\Entity\Grade;
use App\Entity\GradeLevel;
use App\Entity\GradeLock;
use App\Entity\GradeOpening;
use App\Entity\GradeUnlock;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Sf1Remark;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\TransferRequest;
use App\Entity\TransferStatus;
use App\Entity\User;
use App\Grading\GradeRecorder;
use App\Grading\GradeRules;
use App\Grading\GradingException;
use App\Repository\EnrollmentRepository;
use App\Repository\GradeLockRepository;
use App\Repository\GradeOpeningRepository;
use App\Repository\GradeRepository;
use App\Repository\GradeUnlockRepository;
use App\Repository\TransferRequestRepository;
use App\Service\ActivityLogger;
use App\Service\LearnerLock;
use App\Service\SchoolClock;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

class GradeRecorderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $honest;
    private User $teacher;
    private User $principal;
    private Subject $math;
    private TeachingLoad $load;
    private GradingPeriod $term1;
    /** @var list<Enrollment> */
    private array $enrolled = [];
    private int $lrn = 120017170100;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2025-08-22 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->sy->rebuildGradingPeriods();
        $this->em->flush();
        $this->term1 = $this->sy->getGradingPeriod(1);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->teacher = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->principal = $this->plainUser('rosa', User::ROLE_PRINCIPAL);
        $this->honest = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Honest');
        $this->math = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')->setName('Mathematics')->setPosition(1);
        $this->math->setMeetingsPerWeek(5);
        $this->em->persist($this->math);
        $this->load = new TeachingLoad($this->honest, $this->math, $this->teacher);
        $this->em->persist($this->load);
        foreach (['Arnol', 'Bañez', 'Cruz'] as $name) {
            $this->enrolled[] = $this->enrolled($name, $this->honest);
        }
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function enrolled(string $last, Section $section): Enrollment
    {
        $learner = $this->learner((string) ++$this->lrn, $last, 'Learner')->setSex(SexAtBirth::Male);

        return $this->enroll($learner, $this->sy, $section, $this->teacher);
    }

    private function recorder(): GradeRecorder
    {
        $c = static::getContainer();
        $rules = new GradeRules(
            $c->get(GradeRepository::class),
            $c->get(GradeLockRepository::class),
            $c->get(GradeUnlockRepository::class),
            $c->get(EnrollmentRepository::class),
            $c->get(TransferRequestRepository::class),
            $c->get(GradeOpeningRepository::class),
        );

        return new GradeRecorder(
            $this->em,
            $c->get(LockFactory::class),
            $rules,
            $c->get(GradeRepository::class),
            $c->get(GradeLockRepository::class),
            $c->get(GradeUnlockRepository::class),
            $c->get(ActivityLogger::class),
            new SchoolClock($c->get(ClockInterface::class), 'Asia/Manila'),
        );
    }

    private function id(int $i): int
    {
        return $this->enrolled[$i]->getId();
    }

    /** @param array<int, ?int> $values */
    private function save(array $values): int
    {
        return $this->recorder()->saveSheet($this->load, $this->term1, $values, $this->teacher);
    }

    private function refusal(callable $action): string
    {
        try {
            $action();
        } catch (GradingException $e) {
            return $e->getMessage();
        }
        self::fail('Expected a GradingException.');
    }

    private function lock(): void
    {
        $this->em->persist(new GradeLock($this->school, $this->term1, $this->principal, new \DateTimeImmutable('2025-08-22 10:00')));
        $this->em->flush();
    }

    /** Opens or closes Term 1 for grading at the school, as its principal would. */
    private function setGradingOpen(bool $open): void
    {
        $opening = $this->em->getRepository(GradeOpening::class)->findOneBy(['school' => $this->school, 'gradingPeriod' => $this->term1]);
        if ($open && null === $opening) {
            $this->openGrading($this->school, $this->term1);
        } elseif (!$open && null !== $opening) {
            $this->em->remove($opening);
            $this->em->flush();
        }
    }

    private function gradeCount(): int
    {
        return $this->em->getRepository(Grade::class)->count([]);
    }

    private function logged(string $action): bool
    {
        foreach ($this->em->getRepository(ActivityLog::class)->findAll() as $log) {
            if ($log->getAction() === $action) {
                return true;
            }
        }

        return false;
    }

    public function testSaveUpsertsAndDeletes(): void
    {
        $this->setGradingOpen(true);

        self::assertSame(2, $this->save([$this->id(0) => 85, $this->id(1) => 90, $this->id(2) => null]));
        self::assertSame(2, $this->gradeCount());
        self::assertTrue($this->logged('Saved Term 1 grades of Mathematics – Grade 8 – HONEST'));

        self::assertSame(2, $this->save([$this->id(0) => 88, $this->id(1) => null, $this->id(2) => 100]));
        self::assertSame(2, $this->gradeCount());
        $grades = $this->em->getRepository(Grade::class)->findAll();
        $values = [];
        foreach ($grades as $grade) {
            $values[$grade->getEnrollment()->getId()] = $grade->getValue();
        }
        self::assertSame([$this->id(0) => 88, $this->id(2) => 100], $values);
    }

    public function testSaveRejectsOutOfRangeValue(): void
    {
        $this->setGradingOpen(true);

        self::assertSame('Enter a grade from 60 to 100.', $this->refusal(fn () => $this->save([$this->id(0) => 59])));
        self::assertSame(0, $this->gradeCount());
    }

    public function testSaveRefusedWhenLocked(): void
    {
        $this->setGradingOpen(true);
        $this->save([$this->id(0) => 85]);
        $this->lock();

        $message = $this->refusal(fn () => $this->save([$this->id(0) => 70, $this->id(1) => 90]));

        self::assertSame('Term 1 is locked by the principal.', $message);
        $this->em->clear();
        $grades = $this->em->getRepository(Grade::class)->findAll();
        self::assertCount(1, $grades);
        self::assertSame(85, $grades[0]->getValue());
    }

    public function testSaveRefusedWhenClosed(): void
    {
        $this->setGradingOpen(false);

        self::assertSame("Term 1 isn't open for grading.", $this->refusal(fn () => $this->save([$this->id(0) => 85])));
        self::assertSame(0, $this->gradeCount());
    }

    public function testOtherSchoolCannotSaveWhenOnlyOneIsOpen(): void
    {
        $this->setGradingOpen(true);
        $other = $this->school('303197', 'Other National High School');
        $otherTeacher = $this->plainUser('lina', User::ROLE_TEACHER);
        $brilliant = $this->newSection($other, $this->sy, GradeLevel::Grade8, 'Brilliant');
        $otherMath = (new Subject($other, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')->setName('Mathematics')->setPosition(1);
        $otherMath->setMeetingsPerWeek(5);
        $this->em->persist($otherMath);
        $otherLoad = new TeachingLoad($brilliant, $otherMath, $otherTeacher);
        $this->em->persist($otherLoad);
        $learner = $this->enrolled('Zamora', $brilliant);
        $this->em->flush();

        self::assertSame("Term 1 isn't open for grading.", $this->refusal(fn () => $this->recorder()->saveSheet($otherLoad, $this->term1, [$learner->getId() => 85], $otherTeacher)));
        self::assertSame(0, $this->gradeCount());
        // the school that opened it still saves
        self::assertSame(1, $this->save([$this->id(0) => 85]));
    }

    public function testSaveAllowedWhenUnlockedAfterSchoolClosed(): void
    {
        $this->setGradingOpen(true);
        $this->save([$this->id(0) => 85]);
        $this->lock();
        $this->setGradingOpen(false);
        $this->recorder()->unlockClass($this->honest, $this->math, $this->term1, $this->principal);

        self::assertSame(2, $this->save([$this->id(0) => 86, $this->id(1) => 91]));
        self::assertSame(2, $this->gradeCount());
    }

    public function testIgnoresDroppedAndOffSheetIds(): void
    {
        $this->setGradingOpen(true);
        $this->enrolled[2]->setRemarkCode(Sf1Remark::Dropped);
        $other = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Brilliant');
        $stranger = $this->enrolled('Zamora', $other);
        $this->em->flush();

        $graded = $this->save([$this->id(0) => 85, $this->id(2) => 90, $stranger->getId() => 95, 999999 => 80]);

        self::assertSame(1, $graded);
        self::assertSame(1, $this->gradeCount());
    }

    public function testLockClearsUnlocks(): void
    {
        $this->setGradingOpen(true);
        $this->em->persist(new GradeUnlock($this->honest, $this->math, $this->term1, $this->principal, new \DateTimeImmutable('2025-08-22 11:00')));
        $this->em->persist(new GradeUnlock($this->honest, $this->math, $this->sy->getGradingPeriod(2), $this->principal, new \DateTimeImmutable('2025-08-22 11:00')));
        $this->em->flush();

        $this->recorder()->lockPeriod($this->school, $this->term1, $this->principal);

        self::assertSame(1, $this->em->getRepository(GradeLock::class)->count([]));
        $unlocks = $this->em->getRepository(GradeUnlock::class)->findAll();
        self::assertCount(1, $unlocks);
        self::assertSame(2, $unlocks[0]->getGradingPeriod()->getNumber(), "another period's unlock stays");
        self::assertSame('Term 1 is already locked.', $this->refusal(fn () => $this->recorder()->lockPeriod($this->school, $this->term1, $this->principal)));
        self::assertTrue($this->logged('Locked Term 1 grades'));
    }

    public function testLockRefusedWhenNeverOpened(): void
    {
        $this->setGradingOpen(false);

        self::assertSame("Term 1 hasn't been opened for grading yet.", $this->refusal(fn () => $this->recorder()->lockPeriod($this->school, $this->term1, $this->principal)));
        self::assertSame(0, $this->em->getRepository(GradeLock::class)->count([]));
    }

    public function testLockAllowedAfterAdminClosedWithGrades(): void
    {
        $this->setGradingOpen(true);
        $this->save([$this->id(0) => 85]);
        $this->setGradingOpen(false);

        $this->recorder()->lockPeriod($this->school, $this->term1, $this->principal);

        self::assertSame(1, $this->em->getRepository(GradeLock::class)->count([]));
    }

    public function testUnlockAndRelockClassRefusals(): void
    {
        $this->setGradingOpen(true);
        $recorder = $this->recorder();

        self::assertSame('Term 1 is not locked.', $this->refusal(fn () => $recorder->unlockClass($this->honest, $this->math, $this->term1, $this->principal)));
        self::assertSame('This class is not unlocked.', $this->refusal(fn () => $recorder->lockClass($this->honest, $this->math, $this->term1)));

        $recorder->lockPeriod($this->school, $this->term1, $this->principal);
        $recorder->unlockClass($this->honest, $this->math, $this->term1, $this->principal);
        self::assertSame('This class is already unlocked.', $this->refusal(fn () => $recorder->unlockClass($this->honest, $this->math, $this->term1, $this->principal)));

        $recorder->lockClass($this->honest, $this->math, $this->term1);
        self::assertSame(0, $this->em->getRepository(GradeUnlock::class)->count([]));
        self::assertTrue($this->logged('Locked Term 1 grades of Mathematics – Grade 8 – HONEST again'));
    }

    public function testUnlockPeriod(): void
    {
        $this->setGradingOpen(true);
        $recorder = $this->recorder();
        self::assertSame('Term 1 is not locked.', $this->refusal(fn () => $recorder->unlockPeriod($this->school, $this->term1)));

        $recorder->lockPeriod($this->school, $this->term1, $this->principal);
        $recorder->unlockClass($this->honest, $this->math, $this->term1, $this->principal);
        $recorder->unlockPeriod($this->school, $this->term1);

        self::assertSame(0, $this->em->getRepository(GradeLock::class)->count([]));
        self::assertSame(0, $this->em->getRepository(GradeUnlock::class)->count([]));
        self::assertTrue($this->logged('Unlocked Term 1 grades'));
        self::assertSame(1, $this->save([$this->id(0) => 85]));
    }

    /** As TransferManager::approve does: the same enrollment row moves to the receiving section. */
    private function transfer(Enrollment $enrollment, Section $to): void
    {
        $request = new TransferRequest($enrollment, $to, $this->teacher, new \DateTimeImmutable('2025-08-22 08:00'));
        $request->decide(TransferStatus::Approved, $this->principal, new \DateTimeImmutable('2025-08-22 09:00'));
        $enrollment->moveTo($to);
        $this->em->persist($request);
        $this->em->flush();
    }

    /** @return array<int, int> grade values by enrollment id, read back from the database */
    private function savedValues(): array
    {
        $this->em->clear();
        $values = [];
        foreach ($this->em->getRepository(Grade::class)->findAll() as $grade) {
            $values[$grade->getEnrollment()->getId()] = $grade->getValue();
        }
        ksort($values);

        return $values;
    }

    public function testTransferredOutAndDroppedLearnersKeepTheirGrades(): void
    {
        $this->setGradingOpen(true);
        $this->save([$this->id(0) => 85, $this->id(1) => 90, $this->id(2) => 95]);
        $this->transfer($this->enrolled[1], $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Brilliant'));
        $this->enrolled[2]->setRemarkCode(Sf1Remark::Dropped);
        $this->em->flush();

        // a value for each read-only row
        self::assertSame(1, $this->save([$this->id(0) => 86, $this->id(1) => 70, $this->id(2) => 71]));
        self::assertSame([$this->id(0) => 86, $this->id(1) => 90, $this->id(2) => 95], $this->savedValues());

        // a cleared box for each read-only row
        $this->load = $this->em->find(TeachingLoad::class, $this->load->getId());
        $this->term1 = $this->em->find(GradingPeriod::class, $this->term1->getId());
        $this->teacher = $this->em->find(User::class, $this->teacher->getId());
        self::assertSame(1, $this->save([$this->id(0) => 87, $this->id(1) => null, $this->id(2) => null]));
        self::assertSame([$this->id(0) => 87, $this->id(1) => 90, $this->id(2) => 95], $this->savedValues());
    }

    public function testOutOfRangeValueChangesNoOtherGrade(): void
    {
        $this->setGradingOpen(true);
        $this->save([$this->id(0) => 80]);

        // the first row is valid and comes before the bad one
        self::assertSame('Enter a grade from 60 to 100.', $this->refusal(fn () => $this->save([$this->id(0) => 85, $this->id(1) => 90, $this->id(2) => 101])));

        // nothing was changed in memory either, so a later flush writes nothing
        $this->em->flush();
        self::assertSame([$this->id(0) => 80], $this->savedValues());
    }

    public function testSaveIsRefusedWhileThePrincipalIsLocking(): void
    {
        $this->setGradingOpen(true);
        $locking = static::getContainer()->get(LockFactory::class)->createLock('grade-lock-'.$this->school->getId());
        self::assertTrue($locking->acquire());

        try {
            self::assertSame(LearnerLock::BUSY, $this->refusal(fn () => $this->save([$this->id(0) => 85])));
        } finally {
            $locking->release();
        }
        self::assertSame(0, $this->gradeCount());

        // saves of different classes of the school only share the lock, so they don't block each other
        $other = static::getContainer()->get(LockFactory::class)->createLock('grade-lock-'.$this->school->getId());
        self::assertTrue($other->acquireRead());
        try {
            self::assertSame(1, $this->save([$this->id(0) => 85]));
        } finally {
            $other->release();
        }
    }
}
