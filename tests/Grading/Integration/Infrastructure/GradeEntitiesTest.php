<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Infrastructure;

use App\Grading\Domain\Model\GradeOpening;
use App\Grading\Domain\Model\GradeChangeRequest;
use App\Grading\Domain\Model\GradeChangeLine;
use App\Grading\Domain\Model\CardSlipComment;
use App\Learners\Domain\Model\Enrollment;
use App\Grading\Domain\Model\Grade;
use App\Schools\Domain\Model\GradeLevel;
use App\Grading\Domain\Model\GradeLock;
use App\Grading\Domain\Model\GradeUnlock;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Classes\Domain\Model\Subject;
use App\Access\Domain\Model\User;
use App\Grading\Domain\Model\Repository\GradeLockRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeUnlockRepositoryInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class GradeEntitiesTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $section;
    private User $teacher;
    private Enrollment $enrollment;
    private Subject $math;
    private Subject $science;
    private GradingPeriod $term1;
    private GradingPeriod $term2;
    private \DateTimeImmutable $at;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->at = new \DateTimeImmutable('2025-08-22 10:00');
        $this->sy = $this->schoolYear(2025, current: true);
        $this->sy->rebuildGradingPeriods();
        $this->em->flush();
        $this->term1 = $this->sy->getGradingPeriod(1) ?? throw new \LogicException('No period 1');
        $this->term2 = $this->sy->getGradingPeriod(2) ?? throw new \LogicException('No period 2');
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->teacher = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic');
        $this->enrollment = $this->enroll(
            $this->learner('120017170001', 'Arnol', 'Gerald'),
            $this->sy,
            $this->section,
            $this->teacher,
        );
        $this->math = $this->subject('MATH8', 'Mathematics');
        $this->science = $this->subject('SCI8', 'Science');
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function subject(string $code, string $name): Subject
    {
        $subject = (new Subject($this->school, $this->sy))
            ->setGradeLevel(GradeLevel::Grade8)
            ->setCode($code)
            ->setName($name)
            ->setPosition(1)
            ->setMeetingsPerWeek(5);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function grade(Enrollment $enrollment, Subject $subject, GradingPeriod $period, int $value): Grade
    {
        $grade = new Grade($enrollment, $subject, $period, $value, $this->teacher, $this->at);
        $this->em->persist($grade);
        $this->em->flush();

        return $grade;
    }

    /** After a failed flush the manager is closed; swap in a fresh one so tearDown can clean up. */
    private function resetEntityManager(): void
    {
        static::getContainer()->get('doctrine')->resetManager();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function found(EntityManagerInterface $em, string $class, mixed $id): object
    {
        $entity = $em->find($class, $id);
        self::assertNotNull($entity);

        return $entity;
    }

    public function testLocksOpeningsUnlocksLinesAndCommentsKeepWhatTheyWereGiven(): void
    {
        // Initialize
        $lock = new GradeLock($this->school, $this->term1, $this->teacher, $this->at);
        $opening = new GradeOpening($this->school, $this->term1, $this->teacher, $this->at);
        $unlock = new GradeUnlock($this->section, $this->math, $this->term1, $this->teacher, $this->at);
        $request = new GradeChangeRequest(
            $this->section,
            $this->math,
            $this->term1,
            $this->teacher,
            'Encoding error on a quiz',
            $this->at,
        );
        $request->addLine($this->enrollment, 80, 85);
        $comment = new CardSlipComment($this->enrollment, $this->term1);
        foreach ([$lock, $opening, $unlock, $request, $comment] as $entity) {
            $this->em->persist($entity);
        }

        // Execute
        $this->em->flush();
        $line = $request->getLines()->first();

        // Assert
        self::assertInstanceOf(GradeChangeLine::class, $line);
        self::assertNotNull($lock->getId());
        self::assertNotNull($opening->getId());
        self::assertNotNull($line->getId());
        self::assertSame(
            [$this->school, $this->term1, $this->school, $this->term1, $request, $this->term1],
            [
                $lock->getSchool(),
                $lock->getGradingPeriod(),
                $opening->getSchool(),
                $opening->getGradingPeriod(),
                $line->getRequest(),
                $comment->getGradingPeriod(),
            ],
        );
        self::assertSame([$this->teacher, $this->at], [$unlock->getUnlockedBy(), $unlock->getUnlockedAt()]);
    }

    public function testPersistAndReloadGrade(): void
    {
        $id = $this->grade($this->enrollment, $this->math, $this->term1, 88)->getId();
        $this->em->clear();

        $loaded = $this->found($this->em, Grade::class, $id);
        self::assertSame(88, $loaded->getValue());
        self::assertSame($this->enrollment->getId(), $loaded->getEnrollment()->getId());
        self::assertSame($this->math->getId(), $loaded->getSubject()->getId());
        self::assertSame($this->term1->getId(), $loaded->getGradingPeriod()->getId());
        self::assertSame($this->teacher->getId(), $loaded->getUpdatedBy()->getId());

        $later = new \DateTimeImmutable('2025-08-23 09:00');
        $loaded->change(91, $loaded->getUpdatedBy(), $later);
        $this->em->flush();
        $this->em->clear();
        $reloaded = $this->found($this->em, Grade::class, $id);
        self::assertSame(91, $reloaded->getValue());
        self::assertEquals($later, $reloaded->getUpdatedAt());
    }

    public function testOneGradePerEnrollmentSubjectAndPeriod(): void
    {
        $this->grade($this->enrollment, $this->math, $this->term1, 88);
        $this->grade($this->enrollment, $this->science, $this->term1, 80);
        $this->grade($this->enrollment, $this->math, $this->term2, 85);

        try {
            $this->grade($this->enrollment, $this->math, $this->term1, 90);
            self::fail('Expected a unique violation');
        } catch (UniqueConstraintViolationException) {
            $this->resetEntityManager();
            $this->addToAssertionCount(1);
        }
    }

    public function testOneLockPerSchoolAndPeriod(): void
    {
        $this->em->persist(new GradeLock($this->school, $this->term1, $this->teacher, $this->at));
        $this->em->persist(new GradeLock($this->school, $this->term2, $this->teacher, $this->at));
        $this->em->flush();

        $repository = static::getContainer()->get(GradeLockRepositoryInterface::class);
        self::assertSame(
            $this->teacher->getId(),
            $repository->findFor($this->school, $this->term1)?->getLockedBy()->getId(),
        );

        try {
            $this->em->persist(new GradeLock($this->school, $this->term1, $this->teacher, $this->at));
            $this->em->flush();
            self::fail('Expected a unique violation');
        } catch (UniqueConstraintViolationException) {
            $this->resetEntityManager();
            $this->addToAssertionCount(1);
        }
    }

    public function testOneUnlockPerClassSubjectAndPeriod(): void
    {
        $this->em->persist(new GradeUnlock($this->section, $this->math, $this->term1, $this->teacher, $this->at));
        $this->em->persist(new GradeUnlock($this->section, $this->science, $this->term1, $this->teacher, $this->at));
        $this->em->flush();

        $repository = static::getContainer()->get(GradeUnlockRepositoryInterface::class);
        self::assertNotNull($repository->findFor($this->section, $this->math, $this->term1));
        self::assertNull($repository->findFor($this->section, $this->math, $this->term2));

        try {
            $this->em->persist(new GradeUnlock($this->section, $this->math, $this->term1, $this->teacher, $this->at));
            $this->em->flush();
            self::fail('Expected a unique violation');
        } catch (UniqueConstraintViolationException) {
            $this->resetEntityManager();
            $this->addToAssertionCount(1);
        }
    }

    public function testUnenrollDeletesGrades(): void
    {
        $this->grade($this->enrollment, $this->math, $this->term1, 88);
        $this->grade($this->enrollment, $this->science, $this->term1, 80);

        $this->em->remove($this->enrollment);
        $this->em->flush();
        $this->em->clear();

        self::assertSame(0, $this->em->getRepository(Grade::class)->count([]));
    }

    public function testFindForEnrollmentsAndCounts(): void
    {
        $other = $this->enroll(
            $this->learner('120017170002', 'Bello', 'Ana'),
            $this->sy,
            $this->section,
            $this->teacher,
        );
        $notAsked = $this->enroll(
            $this->learner('120017170003', 'Cruz', 'Ana'),
            $this->sy,
            $this->section,
            $this->teacher,
        );
        $this->grade($this->enrollment, $this->math, $this->term1, 88);
        $this->grade($other, $this->math, $this->term1, 75);
        $this->grade($notAsked, $this->math, $this->term1, 70);
        $this->grade($this->enrollment, $this->science, $this->term1, 80);
        $this->grade($this->enrollment, $this->math, $this->term2, 85);

        $repository = static::getContainer()->get(GradeRepositoryInterface::class);
        $ids = [(int) $this->enrollment->getId(), (int) $other->getId()];

        $sheet = $repository->findForEnrollments($ids, $this->math, $this->term1);
        self::assertEqualsCanonicalizing($ids, array_keys($sheet));
        self::assertSame(88, $sheet[(int) $this->enrollment->getId()]->getValue());
        self::assertSame([], $repository->findForEnrollments([], $this->math, $this->term1));
        self::assertSame([], $repository->findForEnrollmentsByPeriod([], $this->term1));

        $bySection = $repository->findForEnrollmentsByPeriod($ids, $this->term1);
        self::assertEqualsCanonicalizing($ids, array_keys($bySection));
        self::assertSame(88, $bySection[(int) $this->enrollment->getId()][(int) $this->math->getId()]->getValue());
        self::assertSame(80, $bySection[(int) $this->enrollment->getId()][(int) $this->science->getId()]->getValue());
        self::assertSame(75, $bySection[(int) $other->getId()][(int) $this->math->getId()]->getValue());
        self::assertArrayNotHasKey((int) $this->science->getId(), $bySection[(int) $other->getId()]);

        self::assertSame(4, $repository->countForSchool($this->school, $this->term1));
        self::assertSame(4, $repository->countForPeriod($this->term1));
        self::assertSame(3, $repository->countForEnrollment($this->enrollment));
        self::assertSame(1, $repository->countForEnrollment($other));
        self::assertSame(1, $repository->countForSchool($this->school, $this->term2));
        self::assertSame(3, $repository->countForSubject($this->math));
    }

    public function testDeleteForSchoolRemovesOnlyThatSchoolsUnlocksForThePeriod(): void
    {
        $otherSchool = $this->school('303197', 'Other High School');
        $otherSection = $this->newSection($otherSchool, $this->sy, GradeLevel::Grade8, 'Bright');
        $this->em->persist(new GradeUnlock($this->section, $this->math, $this->term1, $this->teacher, $this->at));
        $this->em->persist(new GradeUnlock($this->section, $this->science, $this->term1, $this->teacher, $this->at));
        $this->em->persist(new GradeUnlock($this->section, $this->math, $this->term2, $this->teacher, $this->at));
        $this->em->persist(new GradeUnlock($otherSection, $this->math, $this->term1, $this->teacher, $this->at));
        $this->em->flush();

        $repository = static::getContainer()->get(GradeUnlockRepositoryInterface::class);
        self::assertCount(2, $repository->findForSchool($this->school, $this->term1));

        self::assertSame(2, $repository->deleteForSchool($this->school, $this->term1));
        $this->em->clear();

        self::assertCount(0, $repository->findForSchool($this->school, $this->term1));
        self::assertCount(1, $repository->findForSchool($this->school, $this->term2));
        self::assertCount(1, $repository->findForSchool($otherSchool, $this->term1));
        self::assertSame(0, $repository->deleteForSchool($this->school, $this->term1));
    }
}
