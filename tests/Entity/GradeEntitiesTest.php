<?php

namespace App\Tests\Entity;

use App\Entity\Enrollment;
use App\Entity\Grade;
use App\Entity\GradeLevel;
use App\Entity\GradeLock;
use App\Entity\GradeUnlock;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use App\Repository\GradeLockRepository;
use App\Repository\GradeRepository;
use App\Repository\GradeUnlockRepository;
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
        $this->term1 = $this->sy->getGradingPeriod(1);
        $this->term2 = $this->sy->getGradingPeriod(2);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->teacher = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic');
        $this->enrollment = $this->enroll($this->learner('120017170001', 'Arnol', 'Gerald'), $this->sy, $this->section, $this->teacher);
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
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode($code)->setName($name)->setPosition(1)->setMeetingsPerWeek(5);
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

    public function testPersistAndReloadGrade(): void
    {
        $id = $this->grade($this->enrollment, $this->math, $this->term1, 88)->getId();
        $this->em->clear();

        $loaded = $this->em->find(Grade::class, $id);
        self::assertSame(88, $loaded->getValue());
        self::assertSame($this->enrollment->getId(), $loaded->getEnrollment()->getId());
        self::assertSame($this->math->getId(), $loaded->getSubject()->getId());
        self::assertSame($this->term1->getId(), $loaded->getGradingPeriod()->getId());
        self::assertSame($this->teacher->getId(), $loaded->getUpdatedBy()->getId());

        $later = new \DateTimeImmutable('2025-08-23 09:00');
        $loaded->change(91, $loaded->getUpdatedBy(), $later);
        $this->em->flush();
        $this->em->clear();
        $reloaded = $this->em->find(Grade::class, $id);
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
            self::assertTrue(true);
        }
    }

    public function testOneLockPerSchoolAndPeriod(): void
    {
        $this->em->persist(new GradeLock($this->school, $this->term1, $this->teacher, $this->at));
        $this->em->persist(new GradeLock($this->school, $this->term2, $this->teacher, $this->at));
        $this->em->flush();

        $repository = $this->em->getRepository(GradeLock::class);
        self::assertInstanceOf(GradeLockRepository::class, $repository);
        self::assertSame($this->teacher->getId(), $repository->findFor($this->school, $this->term1)->getLockedBy()->getId());

        try {
            $this->em->persist(new GradeLock($this->school, $this->term1, $this->teacher, $this->at));
            $this->em->flush();
            self::fail('Expected a unique violation');
        } catch (UniqueConstraintViolationException) {
            $this->resetEntityManager();
            self::assertTrue(true);
        }
    }

    public function testOneUnlockPerClassSubjectAndPeriod(): void
    {
        $this->em->persist(new GradeUnlock($this->section, $this->math, $this->term1, $this->teacher, $this->at));
        $this->em->persist(new GradeUnlock($this->section, $this->science, $this->term1, $this->teacher, $this->at));
        $this->em->flush();

        $repository = $this->em->getRepository(GradeUnlock::class);
        self::assertNotNull($repository->findFor($this->section, $this->math, $this->term1));
        self::assertNull($repository->findFor($this->section, $this->math, $this->term2));

        try {
            $this->em->persist(new GradeUnlock($this->section, $this->math, $this->term1, $this->teacher, $this->at));
            $this->em->flush();
            self::fail('Expected a unique violation');
        } catch (UniqueConstraintViolationException) {
            $this->resetEntityManager();
            self::assertTrue(true);
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
        $other = $this->enroll($this->learner('120017170002', 'Bello', 'Ana'), $this->sy, $this->section, $this->teacher);
        $notAsked = $this->enroll($this->learner('120017170003', 'Cruz', 'Ana'), $this->sy, $this->section, $this->teacher);
        $this->grade($this->enrollment, $this->math, $this->term1, 88);
        $this->grade($other, $this->math, $this->term1, 75);
        $this->grade($notAsked, $this->math, $this->term1, 70);
        $this->grade($this->enrollment, $this->science, $this->term1, 80);
        $this->grade($this->enrollment, $this->math, $this->term2, 85);

        $repository = $this->em->getRepository(Grade::class);
        self::assertInstanceOf(GradeRepository::class, $repository);
        $ids = [$this->enrollment->getId(), $other->getId()];

        $sheet = $repository->findForEnrollments($ids, $this->math, $this->term1);
        self::assertEqualsCanonicalizing($ids, array_keys($sheet));
        self::assertSame(88, $sheet[$this->enrollment->getId()]->getValue());
        self::assertSame([], $repository->findForEnrollments([], $this->math, $this->term1));
        self::assertSame([], $repository->findForEnrollmentsByPeriod([], $this->term1));

        $bySection = $repository->findForEnrollmentsByPeriod($ids, $this->term1);
        self::assertEqualsCanonicalizing($ids, array_keys($bySection));
        self::assertSame(88, $bySection[$this->enrollment->getId()][$this->math->getId()]->getValue());
        self::assertSame(80, $bySection[$this->enrollment->getId()][$this->science->getId()]->getValue());
        self::assertSame(75, $bySection[$other->getId()][$this->math->getId()]->getValue());
        self::assertArrayNotHasKey($this->science->getId(), $bySection[$other->getId()]);

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

        $repository = $this->em->getRepository(GradeUnlock::class);
        self::assertInstanceOf(GradeUnlockRepository::class, $repository);
        self::assertCount(2, $repository->findForSchool($this->school, $this->term1));

        self::assertSame(2, $repository->deleteForSchool($this->school, $this->term1));
        $this->em->clear();

        self::assertCount(0, $repository->findForSchool($this->school, $this->term1));
        self::assertCount(1, $repository->findForSchool($this->school, $this->term2));
        self::assertCount(1, $repository->findForSchool($otherSchool, $this->term1));
        self::assertSame(0, $repository->deleteForSchool($this->school, $this->term1));
    }
}
