<?php

declare(strict_types=1);

namespace App\Tests\Attendance\Integration\Infrastructure;

use App\Attendance\Domain\Model\AttendanceMark;
use App\Learners\Domain\Model\Enrollment;
use App\Schools\Domain\Model\GradeLevel;
use App\Attendance\Domain\Model\LearnerMark;
use App\Attendance\Domain\Model\RollCall;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Attendance\Domain\Model\Sf2Submission;
use App\Access\Domain\Model\User;
use App\Attendance\Domain\Model\Repository\RollCallRepositoryInterface;
use App\Attendance\Domain\Model\Repository\Sf2SubmissionRepositoryInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class RollCallRepositoryTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private Section $honest;
    private User $adviser;
    private Enrollment $enrollment;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic');
        $this->honest = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Honest');
        $this->enrollment = $this->enroll(
            $this->learner('120017170001', 'Arnol', 'Gerald'),
            $this->sy,
            $this->artistic,
            $this->adviser,
        );
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function found(string $class, ?int $id): object
    {
        $entity = $this->em->find($class, $id);
        self::assertNotNull($entity);

        return $entity;
    }

    private function rollCall(Section $section, string $date): RollCall
    {
        $rollCall = new RollCall(
            $section,
            new \DateTimeImmutable($date),
            $this->adviser,
            new \DateTimeImmutable($date . ' 07:30'),
        );
        $this->em->persist($rollCall);
        $this->em->flush();

        return $rollCall;
    }

    public function testFindBetweenKeysByDate(): void
    {
        $this->rollCall($this->artistic, '2025-08-05');
        $this->rollCall($this->artistic, '2025-08-04')->replaceMarks(
            [(int) $this->enrollment->getId() => [$this->enrollment, LearnerMark::Absent]],
        );
        $this->rollCall($this->honest, '2025-08-04');
        $this->rollCall($this->artistic, '2025-09-01');
        $this->em->flush();
        $this->em->clear();

        $repository = static::getContainer()->get(RollCallRepositoryInterface::class);
        $artistic = $this->found(Section::class, $this->artistic->getId());
        $found = $repository->findBetween(
            $artistic,
            new \DateTimeImmutable('2025-08-01'),
            new \DateTimeImmutable('2025-08-31'),
        );

        self::assertSame(['2025-08-04', '2025-08-05'], array_keys($found));
        self::assertCount(1, $found['2025-08-04']->getMarks());
        $mark = $found['2025-08-04']->getMarks()->first();
        self::assertInstanceOf(AttendanceMark::class, $mark);
        self::assertNotNull($mark->getId());
        self::assertSame(
            '2025-08-04',
            $repository->findOne($artistic, new \DateTimeImmutable('2025-08-04 13:00'))?->getDate()->format('Y-m-d'),
        );
        self::assertNull($repository->findOne($artistic, new \DateTimeImmutable('2025-08-06')));

        $onFourth = $repository->findForSchoolOn(
            $this->found(School::class, $this->school->getId()),
            $this->found(SchoolYear::class, $this->sy->getId()),
            new \DateTimeImmutable('2025-08-04'),
        );
        self::assertEqualsCanonicalizing([$this->artistic->getId(), $this->honest->getId()], array_keys($onFourth));

        $school = $this->found(School::class, $this->school->getId());
        self::assertSame(
            2,
            $repository->countSectionsOn(
                $school,
                [new \DateTimeImmutable('2025-08-04'), new \DateTimeImmutable('2025-08-05')],
            ),
        );
        self::assertSame(1, $repository->countSectionsOn($school, [new \DateTimeImmutable('2025-08-05')]));
        self::assertSame(0, $repository->countSectionsOn($school, []));
    }

    public function testUnenrollDeletesMarks(): void
    {
        $rollCall = $this->rollCall($this->artistic, '2025-08-04');
        $rollCall->replaceMarks([(int) $this->enrollment->getId() => [$this->enrollment, LearnerMark::Absent]]);
        $this->em->flush();
        $rollCallId = $rollCall->getId();

        $this->em->createQuery('DELETE FROM ' . Enrollment::class . ' e WHERE e.id = :id')
            ->setParameter('id', $this->enrollment->getId())
            ->execute();
        $this->em->clear();

        self::assertSame(0, $this->em->getRepository(AttendanceMark::class)->count([]));
        self::assertNotNull($this->em->find(RollCall::class, $rollCallId));
    }

    public function testSubmissions(): void
    {
        $this->em->persist(
            new Sf2Submission(
                $this->artistic,
                new \DateTimeImmutable('2025-08-17'),
                $this->adviser,
                new \DateTimeImmutable('2025-09-03 15:00'),
            ),
        );
        $this->em->flush();
        $this->em->clear();

        $repository = static::getContainer()->get(Sf2SubmissionRepositoryInterface::class);
        $artistic = $this->found(Section::class, $this->artistic->getId());
        $submission = $repository->findOne($artistic, new \DateTimeImmutable('2025-08-01'));

        self::assertNotNull($submission?->getId());
        self::assertSame('2025-08-01', $submission->getMonth()->format('Y-m-d'));
        self::assertNull($repository->findOne($artistic, new \DateTimeImmutable('2025-09-01')));
        $forMonth = $repository->findForMonth(
            $this->found(School::class, $this->school->getId()),
            $this->found(SchoolYear::class, $this->sy->getId()),
            new \DateTimeImmutable('2025-08-01'),
        );
        self::assertSame([$this->artistic->getId()], array_keys($forMonth));
    }

    public function testFindFreshReadsWhatAnotherRequestSavedMeanwhile(): void
    {
        // Initialize
        $rollCall = $this->rollCall($this->artistic, '2025-08-04');
        $rollCall->replaceMarks([(int) $this->enrollment->getId() => [$this->enrollment, LearnerMark::Absent]]);
        $this->em->persist(new Sf2Submission(
            $this->artistic,
            new \DateTimeImmutable('2025-08-01'),
            $this->adviser,
            new \DateTimeImmutable('2025-09-03 15:00'),
        ));
        $this->em->flush();
        $rollCalls = static::getContainer()->get(RollCallRepositoryInterface::class);
        $submissions = static::getContainer()->get(Sf2SubmissionRepositoryInterface::class);
        $day = new \DateTimeImmutable('2025-08-04');
        $month = new \DateTimeImmutable('2025-08-01');
        $connection = $this->em->getConnection();
        $connection->executeStatement(
            "UPDATE attendance_mark SET mark = 'late' WHERE roll_call_id = ?",
            [$rollCall->getId()],
        );
        $connection->executeStatement(
            "UPDATE sf2_submission SET submitted_at = '2025-09-04 08:00:00' WHERE section_id = ?",
            [$this->artistic->getId()],
        );

        // Execute
        $staleMark = $rollCalls->findOne($this->artistic, $day)?->markFor($this->enrollment);
        $freshMark = $rollCalls->findFresh($this->artistic, $day)?->markFor($this->enrollment);
        $staleSubmitted = $submissions->findOne($this->artistic, $month)?->getSubmittedAt()->format('Y-m-d H:i');
        $freshSubmitted = $submissions->findFresh($this->artistic, $month)?->getSubmittedAt()->format('Y-m-d H:i');

        // Assert
        self::assertSame([LearnerMark::Absent, LearnerMark::Late], [$staleMark, $freshMark]);
        self::assertSame(['2025-09-03 15:00', '2025-09-04 08:00'], [$staleSubmitted, $freshSubmitted]);
    }
}
