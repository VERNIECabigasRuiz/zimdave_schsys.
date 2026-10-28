<?php

namespace App\Tests\Service;

use App\Entity\ActivityLog;
use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\Learner;
use App\Entity\Plan;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\TransferRequest;
use App\Entity\User;
use App\Repository\EnrollmentRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\TransferRequestRepository;
use App\Repository\UserRepository;
use App\Service\ActivityLogger;
use App\Service\EnrollmentException;
use App\Service\LearnerEnrollment;
use App\Service\LearnerLock;
use App\Service\PlanLimitException;
use App\Service\PlanLimits;
use App\Service\TemporaryPasswordGenerator;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class LearnerEnrollmentTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private const BUSY = 'Another change is being saved. Please try again.';

    private EntityManagerInterface $em;
    private LearnerEnrollment $service;
    private SchoolYear $sy;
    private School $school;
    private School $other;
    private Section $rizal;
    private Section $mabini;
    private Section $otherSection;
    private User $admin;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-10-16');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS');
        $this->other = $this->school('303457', 'Mandaue NHS');
        $this->rizal = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Rizal');
        $this->mabini = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Mabini');
        $this->otherSection = $this->newSection($this->other, $this->sy, GradeLevel::Grade7, 'Bonifacio');
        $this->admin = $this->plainUser('boss', User::ROLE_ADMIN);
        $this->service = $this->build();
    }

    private function build(?EnrollmentRepository $enrollments = null): LearnerEnrollment
    {
        $c = static::getContainer();
        $realEnrollments = $c->get(EnrollmentRepository::class);

        return new LearnerEnrollment(
            $this->em,
            $c->get(PlanLimits::class),
            new LearnerLock($c->get(LockFactory::class), $c->get(ManagerRegistry::class)),
            $enrollments ?? $realEnrollments,
            $c->get(TransferRequestRepository::class),
            $c->get(SchoolYearRepository::class),
            $c->get(UserRepository::class),
            $c->get(UserPasswordHasherInterface::class),
            new TemporaryPasswordGenerator(),
            $c->get(ActivityLogger::class),
        );
    }

    private function newLearner(string $lrn, string $last = 'Cruz', string $first = 'Juan'): Learner
    {
        return (new Learner(new User()))->setLrn($lrn)->setLastName($last)->setFirstName($first)
            ->setSex(SexAtBirth::Male)->setBirthDate(new \DateTimeImmutable('2012-05-01'));
    }

    /** @return list<string> */
    private function logs(): array
    {
        return array_map(static fn (ActivityLog $l) => $l->getAction(), $this->em->getRepository(ActivityLog::class)->findAll());
    }

    private function refused(string $message, callable $change, string $class = EnrollmentException::class): void
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

    private function setStudentsPerSeat(int $n): void
    {
        $this->em->createQuery('UPDATE '.Plan::class." p SET p.studentsPerSeat = $n WHERE p.name = 'Basic'")->execute();
        $this->em->refresh($this->plan());
    }

    public function testEnrollNewCreatesStudentAccount(): void
    {
        $learner = $this->newLearner('123456789012');
        $password = $this->service->enrollNew($learner, $this->rizal, $this->admin);

        $user = $this->em->getRepository(User::class)->findOneBy(['username' => '123456789012']);
        self::assertNotNull($user);
        self::assertSame(User::ROLE_STUDENT, $user->getRole());
        self::assertTrue($user->mustChangePassword());
        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($user, $password));
        $enrollment = $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $learner]);
        self::assertSame($this->rizal, $enrollment->getSection());
        self::assertSame($this->sy, $enrollment->getSchoolYear());
        self::assertContains('Enrolled 123456789012 in Grade 7 – Rizal', $this->logs());
    }

    public function testEnrollNewRefusesTakenUsername(): void
    {
        $this->plainUser('123456789012', User::ROLE_TEACHER);

        $this->refused('The username 123456789012 is already taken by another account. Ask the administrator.', fn () => $this->service->enrollNew($this->newLearner('123456789012'), $this->rizal, $this->admin));
        self::assertSame(0, $this->em->getRepository(Enrollment::class)->count([]));
    }

    public function testEnrollRefusesSectionOfAnotherYear(): void
    {
        $old = $this->schoolYear(2025, current: false);
        $section = $this->newSection($this->school, $old, GradeLevel::Grade7, 'Old');

        $this->refused('Choose a section of the current school year.', fn () => $this->service->enrollNew($this->newLearner('123456789012'), $section, $this->admin));
    }

    public function testEnrollExistingNextYear(): void
    {
        $old = $this->schoolYear(2025, current: false);
        $oldSection = $this->newSection($this->school, $old, GradeLevel::Grade7, 'Rizal');
        $learner = $this->learner('123456789012', 'Cruz', 'Juan');
        $this->enroll($learner, $old, $oldSection, $this->admin);

        $this->service->enrollExisting($learner, $this->mabini, $this->admin);

        self::assertCount(2, $this->em->getRepository(Enrollment::class)->findBy(['learner' => $learner]));
        self::assertContains('Enrolled 123456789012 in Grade 8 – Mabini', $this->logs());
    }

    public function testAlreadyEnrolledHere(): void
    {
        $learner = $this->learner('123456789012', 'Cruz', 'Juan');
        $this->enroll($learner, $this->sy, $this->rizal, $this->admin);

        $this->refused('Cruz, Juan is already enrolled in Grade 7 – Rizal.', fn () => $this->service->enrollExisting($learner, $this->mabini, $this->admin));
    }

    public function testEnrolledElsewhereRefused(): void
    {
        $learner = $this->learner('123456789012', 'Cruz', 'Juan');
        $this->enroll($learner, $this->sy, $this->otherSection, $this->admin);

        $this->refused('Cruz, Juan is enrolled at Mandaue NHS.', fn () => $this->service->enrollExisting($learner, $this->rizal, $this->admin));
    }

    public function testSchoolLimit(): void
    {
        $this->setStudentsPerSeat(1);
        try {
            $this->enroll($this->learner('100000000001', 'Cruz', 'Ana'), $this->sy, $this->rizal, $this->admin);

            $this->refused(
                'The Basic plan allows 1 student(s) (1 used). Contact the administrator to upgrade.',
                fn () => $this->service->enrollNew($this->newLearner('100000000002'), $this->rizal, $this->admin),
                PlanLimitException::class,
            );
            self::assertSame(1, $this->em->getRepository(Enrollment::class)->count([]));
        } finally {
            $this->setStudentsPerSeat(1000);
        }
    }

    public function testSectionLimitOnPlanWithoutPrincipal(): void
    {
        $solo = (new Plan())->setName('Solo Test')->setTeachersPerSeat(1)->setStudentsPerSeat(1)->setIncludesPrincipal(false);
        $this->em->persist($solo);
        $this->em->flush();
        $school = $this->school('300001', 'Solo School', plan: 'Solo Test', seats: 2);
        $section = $this->newSection($school, $this->sy, GradeLevel::Grade7, 'Rizal');
        $this->enroll($this->learner('100000000001', 'Cruz', 'Ana'), $this->sy, $section, $this->admin);

        $this->refused('Grade 7 – Rizal is full (1 learner).', fn () => $this->service->enrollNew($this->newLearner('100000000002'), $section, $this->admin), PlanLimitException::class);
    }

    public function testLastPlaceIsTakenOnce(): void
    {
        $lock = static::getContainer()->get(LockFactory::class)->createLock('learners-school-'.$this->school->getId());
        self::assertTrue($lock->acquire());
        try {
            $this->refused(self::BUSY, fn () => $this->service->enrollNew($this->newLearner('123456789012'), $this->rizal, $this->admin));
        } finally {
            $lock->release();
        }
        self::assertSame(0, $this->em->getRepository(Enrollment::class)->count([]));
        self::assertNull($this->em->getRepository(Learner::class)->findOneBy(['lrn' => '123456789012']));
    }

    public function testUniqueViolationBecomesFriendlyMessage(): void
    {
        $learner = $this->learner('123456789012', 'Cruz', 'Juan');
        $this->em->getConnection()->insert('enrollment', [
            'learner_id' => $learner->getId(),
            'school_year_id' => $this->sy->getId(),
            'school_id' => $this->school->getId(),
            'section_id' => $this->rizal->getId(),
            'enrolled_by_id' => $this->admin->getId(),
            'enrolled_at' => '2026-10-16 08:00:00',
        ]);
        // a repository that misses the row, as if the other request had not saved yet
        $enrollments = $this->createStub(EnrollmentRepository::class);
        $enrollments->method('findCurrent')->willReturn(null);
        $service = $this->build($enrollments);

        $this->refused(self::BUSY, fn () => $service->enrollExisting($learner, $this->mabini, $this->admin));

        self::assertTrue($this->em->isOpen());
        self::assertSame(1, $this->em->getRepository(Enrollment::class)->count([]));
    }

    public function testChangeLrnUpdatesUsername(): void
    {
        $learner = $this->learner('123456789012', 'Cruz', 'Juan');

        $this->service->changeLrn($learner, '210987654321');

        $this->em->clear();
        $saved = $this->em->getRepository(Learner::class)->find($learner->getId());
        self::assertSame('210987654321', $saved->getLrn());
        self::assertSame('210987654321', $saved->getUser()->getUsername());
        self::assertContains('Changed LRN 123456789012 to 210987654321', $this->logs());
    }

    public function testChangeLrnRefusesTakenUsername(): void
    {
        $learner = $this->learner('123456789012', 'Cruz', 'Juan');
        $this->plainUser('210987654321', User::ROLE_TEACHER);

        $this->refused('The username 210987654321 is already taken by another account. Ask the administrator.', fn () => $this->service->changeLrn($learner, '210987654321'));
        self::assertSame('123456789012', $learner->getLrn());
    }

    public function testUpdateLogs(): void
    {
        $learner = $this->learner('123456789012', 'Cruz', 'Juan');
        $learner->setFirstName('Juanito');

        $this->service->update($learner);

        self::assertContains('Updated learner 123456789012', $this->logs());
        self::assertSame('Cruz, Juanito', $learner->getUser()->getFullName());
    }

    public function testMoveWithinSchool(): void
    {
        $enrollment = $this->enroll($this->learner('123456789012', 'Cruz', 'Juan'), $this->sy, $this->rizal, $this->admin);

        $this->service->move($enrollment, $this->mabini);

        self::assertSame($this->mabini, $enrollment->getSection());
        self::assertContains('Moved 123456789012 from Grade 7 – Rizal to Grade 8 – Mabini', $this->logs());
    }

    public function testMoveToOtherSchoolRefused(): void
    {
        $enrollment = $this->enroll($this->learner('123456789012', 'Cruz', 'Juan'), $this->sy, $this->rizal, $this->admin);

        $this->refused('Choose a section of the same school.', fn () => $this->service->move($enrollment, $this->otherSection));
        self::assertSame($this->rizal, $enrollment->getSection());
    }

    public function testPastYearIsReadOnly(): void
    {
        $old = $this->schoolYear(2025, current: false);
        $oldSection = $this->newSection($this->school, $old, GradeLevel::Grade7, 'Rizal');
        $enrollment = $this->enroll($this->learner('123456789012', 'Cruz', 'Juan'), $old, $oldSection, $this->admin);

        $this->refused('Past school years cannot be changed.', fn () => $this->service->unenroll($enrollment));
        $this->refused('Past school years cannot be changed.', fn () => $this->service->move($enrollment, $oldSection));
    }

    public function testUnenrollKeepsLearnerAndAccount(): void
    {
        $learner = $this->learner('123456789012', 'Cruz', 'Juan');
        $enrollment = $this->enroll($learner, $this->sy, $this->rizal, $this->admin);

        $this->service->unenroll($enrollment);

        self::assertSame(0, $this->em->getRepository(Enrollment::class)->count([]));
        self::assertNotNull($this->em->getRepository(Learner::class)->find($learner->getId()));
        self::assertNotNull($this->em->getRepository(User::class)->findOneBy(['username' => '123456789012']));
        self::assertContains('Unenrolled 123456789012 from Grade 7 – Rizal', $this->logs());
    }

    public function testPendingTransferBlocksMoveAndUnenroll(): void
    {
        $enrollment = $this->enroll($this->learner('123456789012', 'Cruz', 'Juan'), $this->sy, $this->rizal, $this->admin);
        $this->em->persist(new TransferRequest($enrollment, $this->otherSection, $this->admin));
        $this->em->flush();

        $message = 'Cruz, Juan has a transfer request waiting.';
        $this->refused($message, fn () => $this->service->move($enrollment, $this->mabini));
        $this->refused($message, fn () => $this->service->unenroll($enrollment));
        $this->refused($message, fn () => $this->service->transferDirectly($enrollment, $this->otherSection, $this->admin));
        self::assertSame($this->rizal, $enrollment->getSection());
    }

    public function testResetPassword(): void
    {
        $learner = $this->learner('123456789012', 'Cruz', 'Juan');

        $password = $this->service->resetPassword($learner);

        self::assertTrue(static::getContainer()->get(UserPasswordHasherInterface::class)->isPasswordValid($learner->getUser(), $password));
        self::assertTrue($learner->getUser()->mustChangePassword());
        self::assertContains('Reset password of learner 123456789012', $this->logs());
    }

    public function testTransferDirectly(): void
    {
        $enrollment = $this->enroll($this->learner('123456789012', 'Cruz', 'Juan'), $this->sy, $this->rizal, $this->admin);

        $this->service->transferDirectly($enrollment, $this->otherSection, $this->admin);

        self::assertSame($this->other, $enrollment->getSchool());
        self::assertSame($this->otherSection, $enrollment->getSection());
        self::assertContains('Transferred 123456789012 from Pagsabungan NHS to Mandaue NHS', $this->logs());
    }

    public function testTransferDirectlyChecksReceivingLimits(): void
    {
        $enrollment = $this->enroll($this->learner('123456789012', 'Cruz', 'Juan'), $this->sy, $this->rizal, $this->admin);
        $this->other->setActive(false);
        $this->em->flush();

        $this->refused('This school is inactive.', fn () => $this->service->transferDirectly($enrollment, $this->otherSection, $this->admin), PlanLimitException::class);
        self::assertSame($this->school, $enrollment->getSchool());
    }

    public function testBlockedPlanRefusesEveryWrite(): void
    {
        $learner = $this->learner('123456789012', 'Cruz', 'Juan');
        $enrollment = $this->enroll($learner, $this->sy, $this->rizal, $this->admin);
        $newcomer = $this->learner('210987654321', 'Reyes', 'Ana');
        $this->school->setActive(false);
        $this->em->flush();

        foreach ([
            fn () => $this->service->enrollNew($this->newLearner('111111111111'), $this->rizal, $this->admin),
            fn () => $this->service->enrollExisting($newcomer, $this->rizal, $this->admin),
            fn () => $this->service->update($learner),
            fn () => $this->service->changeLrn($learner, '999999999999'),
            fn () => $this->service->move($enrollment, $this->mabini),
            fn () => $this->service->unenroll($enrollment),
            fn () => $this->service->resetPassword($learner),
        ] as $change) {
            $this->refused('This school is inactive.', $change, PlanLimitException::class);
        }
        self::assertSame(1, $this->em->getRepository(Enrollment::class)->count([]));
        self::assertSame('123456789012', $learner->getLrn());
    }
}
