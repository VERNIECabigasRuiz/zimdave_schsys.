<?php

declare(strict_types=1);

namespace App\Tests\Learners\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Exception\SectionNotFoundException;
use App\Classes\Domain\Model\Section;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseBusInterface;
use App\Learners\Application\Query\Command\GetAdvisoryCommand;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\UseCase\Command\EnrollLearnerCommand;
use App\Learners\Application\UseCase\Command\LearnerDetails;
use App\Learners\Application\UseCase\Command\MoveLearnerCommand;
use App\Learners\Application\UseCase\Command\ResetLearnerPasswordCommand;
use App\Learners\Application\UseCase\Command\TransferLearnerCommand;
use App\Learners\Application\UseCase\Command\UnenrollLearnerCommand;
use App\Learners\Application\UseCase\Command\UpdateLearnerCommand;
use App\Learners\Domain\Exception\LearnerNotFoundException;
use App\Learners\Domain\Exception\LearnersAccessDeniedException;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\SexAtBirth;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Tests\SchoolFixturesTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

final class LearnerHandlersTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private const BUSY = 'Another change is being saved. Please try again.';

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private School $other;
    private Section $rizal;
    private Section $mabini;
    private Section $otherSection;
    private User $principal;
    private User $adviser;
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
        $this->principal = $this->plainUser('maria', User::ROLE_PRINCIPAL);
        $this->assign($this->school, $this->sy, $this->principal);
        $this->adviser = $this->plainUser('ana', User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $this->adviser, User::ROLE_TEACHER);
        $this->rizal->setAdviser($this->adviser);
        $this->em->flush();
        $this->admin = $this->plainUser('boss', User::ROLE_ADMIN);
    }

    public function testEnrollsANewLearnerAndAnExistingOne(): void
    {
        // Initialize
        $returning = $this->learner('100000000002', 'Reyes', 'Ana');
        $details = $this->details('100000000001');
        $new = new EnrollLearnerCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            null,
            '100000000001',
            null,
            $details,
        );
        $new->sectionId = (int) $this->rizal->getId();
        $existing = new EnrollLearnerCommand(
            (int) $this->adviser->getId(),
            (int) $this->school->getId(),
            (int) $this->rizal->getId(),
            '100000000002',
            (int) $returning->getId(),
            null,
        );

        // Execute
        $enrolledNew = $this->handle($new);
        $enrolledExisting = $this->handle($existing);

        // Assert
        /** @var array{fullName: string, username: string, password: ?string, sectionLabel: string} $first */
        $first = $enrolledNew->data();
        /** @var array{fullName: string, password: ?string, sectionLabel: string} $second */
        $second = $enrolledExisting->data();
        self::assertSame(
            ['Cruz, Juan', '100000000001', 'Grade 7 – Rizal'],
            [$first['fullName'], $first['username'], $first['sectionLabel']],
        );
        self::assertNotEmpty($first['password']);
        self::assertSame(
            ['Reyes, Ana', null, 'Grade 7 – Rizal'],
            [$second['fullName'], $second['password'], $second['sectionLabel']],
        );
        $this->em->clear();
        self::assertSame(2, $this->em->getRepository(Enrollment::class)->count(['section' => $this->rizal->getId()]));
        $saved = $this->em->getRepository(Learner::class)->findOneBy(['lrn' => '100000000001']);
        self::assertSame('2026-10-16', $saved?->getCreatedAt()->format('Y-m-d'));
        self::assertSame(date_default_timezone_get(), $saved->getCreatedAt()->getTimezone()->getName());
    }

    public function testAHeldLockRefusesTheChangeAndSavesNothing(): void
    {
        // Initialize
        $learner = $this->learner('100000000001', 'Cruz', 'Juan');
        $this->enroll($learner, $this->sy, $this->rizal, $this->admin);
        $factory = static::getContainer()->get(LockFactory::class);
        $schoolLock = $factory->createLock('learners-school-' . $this->school->getId());
        $learnerLock = $factory->createLock('learner-' . $learner->getId());
        $move = new MoveLearnerCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            null,
            (int) $learner->getId(),
            (int) $this->mabini->getId(),
        );
        $reset = new ResetLearnerPasswordCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            null,
            (int) $learner->getId(),
        );

        // Execute
        self::assertTrue($schoolLock->acquire());
        try {
            $moveWhileSchoolBusy = $this->handle($move);
        } finally {
            $schoolLock->release();
        }
        self::assertTrue($learnerLock->acquire());
        try {
            $resetWhileLearnerBusy = $this->handle($reset);
        } finally {
            $learnerLock->release();
        }

        // Assert
        foreach ([$moveWhileSchoolBusy, $resetWhileLearnerBusy] as $result) {
            self::assertSame(
                ['RESOURCE_BUSY', self::BUSY],
                [$result->errorCode(), $result->exception()?->getMessage()],
            );
        }
        $this->em->clear();
        self::assertSame(
            $this->rizal->getId(),
            $this->em->getRepository(Enrollment::class)->findOneBy([])?->getSection()->getId(),
        );
    }

    public function testAnAccountSavedFirstByAnotherRequestComesBackAsAUniqueClash(): void
    {
        // Initialize
        $details = $this->details('100000000001');
        $command = new EnrollLearnerCommand(
            (int) $this->principal->getId(),
            (int) $this->school->getId(),
            null,
            '100000000001',
            null,
            $details,
        );
        $command->sectionId = (int) $this->rizal->getId();
        $this->em->getEventManager()->addEventListener([Events::onFlush], new UsernameTakenOnFlush('100000000001'));

        // Execute
        $result = $this->handle($command);

        // Assert
        self::assertInstanceOf(UniqueConstraintViolationException::class, $result->exception());
        $em = static::getContainer()->get('doctrine')->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        self::assertTrue($em->isOpen());
        self::assertSame(0, $em->getRepository(Enrollment::class)->count([]));
    }

    public function testAChangeMadeBeforeTheLockIsSeenOnceItIsHeld(): void
    {
        // Initialize
        $moved = $this->learner('100000000001', 'Cruz', 'Juan');
        $movedEnrollment = $this->enroll($moved, $this->sy, $this->rizal, $this->admin);
        $gone = $this->learner('100000000002', 'Reyes', 'Ana');
        $goneEnrollment = $this->enroll($gone, $this->sy, $this->rizal, $this->admin);
        $connection = $this->em->getConnection();
        // other requests moved one learner to another school and unenrolled the other
        $connection->update(
            'enrollment',
            ['school_id' => $this->other->getId(), 'section_id' => $this->otherSection->getId()],
            ['id' => $movedEnrollment->getId()],
        );
        $connection->delete('enrollment', ['id' => $goneEnrollment->getId()]);
        $this->em->clear();
        $schoolId = (int) $this->school->getId();
        $actorId = (int) $this->principal->getId();

        // Execute
        $results = [
            $this->handle(
                new MoveLearnerCommand($actorId, $schoolId, null, (int) $moved->getId(), (int) $this->mabini->getId()),
            ),
            $this->handle(new UnenrollLearnerCommand($actorId, $schoolId, null, (int) $moved->getId())),
            $this->handle(new UnenrollLearnerCommand($actorId, $schoolId, null, (int) $gone->getId())),
        ];

        // Assert
        foreach ($results as $result) {
            self::assertInstanceOf(LearnerNotFoundException::class, $result->exception());
        }
        self::assertSame(1, $this->em->getRepository(Enrollment::class)->count([]));
    }

    public function testRefusesWhatTheAccountMayNotDo(): void
    {
        // Initialize
        $learner = $this->learner('100000000001', 'Cruz', 'Juan');
        $this->enroll($learner, $this->sy, $this->rizal, $this->admin);
        $outsider = $this->plainUser('ben', User::ROLE_TEACHER);
        $schoolId = (int) $this->school->getId();
        $learnerId = (int) $learner->getId();

        // Execute
        $results = [
            $this->handle(
                new MoveLearnerCommand(
                    (int) $this->adviser->getId(),
                    $schoolId,
                    null,
                    $learnerId,
                    (int) $this->mabini->getId(),
                ),
            ),
            $this->handle(new UnenrollLearnerCommand((int) $outsider->getId(), $schoolId, null, $learnerId)),
            $this->query(new GetAdvisoryCommand((int) $outsider->getId())),
        ];

        // Assert
        self::assertSame(
            [
                [LearnersAccessDeniedException::class, 'Access Denied.'],
                [LearnersAccessDeniedException::class, 'Access Denied.'],
                [LearnersAccessDeniedException::class, 'You do not advise a section this school year.'],
            ],
            array_map(
                static fn (Result $r) => [get_debug_type($r->exception()), $r->exception()?->getMessage()],
                $results,
            ),
        );
    }

    public function testRefusesASectionOrLearnerTheChangeCannotReach(): void
    {
        // Initialize
        $learner = $this->learner('100000000001', 'Cruz', 'Juan');
        $this->enroll($learner, $this->sy, $this->rizal, $this->admin);
        $adminId = (int) $this->admin->getId();
        $schoolId = (int) $this->school->getId();
        $learnerId = (int) $learner->getId();

        // Execute
        $results = [
            $this->handle(
                new TransferLearnerCommand(
                    $adminId,
                    $schoolId,
                    null,
                    $learnerId,
                    (int) $this->mabini->getId(),
                    $schoolId,
                ),
            ),
            $this->handle(
                new MoveLearnerCommand($adminId, $schoolId, null, $learnerId, (int) $this->otherSection->getId()),
            ),
            $this->handle(new UpdateLearnerCommand(
                (int) $this->principal->getId(),
                $schoolId,
                (int) $this->mabini->getId(),
                $learnerId,
                $this->details('100000000001'),
                false,
            )),
        ];

        // Assert
        self::assertSame(
            [
                [SectionNotFoundException::class, 'This section is not at another school this school year.'],
                [SectionNotFoundException::class, 'This section is not in the school this school year.'],
                [LearnerNotFoundException::class, 'This learner is not enrolled here this school year.'],
            ],
            array_map(
                static fn (Result $r) => [get_debug_type($r->exception()), $r->exception()?->getMessage()],
                $results,
            ),
        );
    }

    public function testThePolicyAnswersOnlyWhatItKnows(): void
    {
        // Initialize
        $learner = $this->learner('100000000001', 'Cruz', 'Juan');
        $policy = static::getContainer()->get(LearnerPolicy::class);

        // Execute
        $unknown = $policy->can($this->admin, 'LEARNER_SOMETHING_ELSE', $learner);
        $wrongSubject = $policy->can($this->admin, LearnerPolicy::EDIT, $this->rizal);

        // Assert
        self::assertFalse($unknown);
        self::assertFalse($wrongSubject);
    }

    /** Valid details of a learner Juan Cruz with this LRN */
    private function details(string $lrn): LearnerDetails
    {
        $details = LearnerDetails::forNew($lrn);
        $details->lastName = 'Cruz';
        $details->firstName = 'Juan';
        $details->sex = SexAtBirth::Male;
        $details->birthDate = new \DateTimeImmutable('2012-05-01');

        return $details;
    }

    private function handle(UseCase $command): Result
    {
        return static::getContainer()->get(UseCaseBusInterface::class)->handle($command);
    }

    private function query(Query $command): Result
    {
        return static::getContainer()->get(QueryBusInterface::class)->handle($command);
    }
}
