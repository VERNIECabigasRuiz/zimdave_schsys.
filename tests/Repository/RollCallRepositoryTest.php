<?php

namespace App\Tests\Repository;

use App\Entity\AttendanceMark;
use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\LearnerMark;
use App\Entity\RollCall;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Sf2Submission;
use App\Entity\User;
use App\Repository\RollCallRepository;
use App\Repository\Sf2SubmissionRepository;
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
        $this->enrollment = $this->enroll($this->learner('120017170001', 'Arnol', 'Gerald'), $this->sy, $this->artistic, $this->adviser);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function rollCall(Section $section, string $date): RollCall
    {
        $rollCall = new RollCall($section, new \DateTimeImmutable($date), $this->adviser, new \DateTimeImmutable($date.' 07:30'));
        $this->em->persist($rollCall);
        $this->em->flush();

        return $rollCall;
    }

    public function testFindBetweenKeysByDate(): void
    {
        $this->rollCall($this->artistic, '2025-08-05');
        $this->rollCall($this->artistic, '2025-08-04')->replaceMarks([$this->enrollment->getId() => [$this->enrollment, LearnerMark::Absent]]);
        $this->rollCall($this->honest, '2025-08-04');
        $this->rollCall($this->artistic, '2025-09-01');
        $this->em->flush();
        $this->em->clear();

        $repository = static::getContainer()->get(RollCallRepository::class);
        $artistic = $this->em->find(Section::class, $this->artistic->getId());
        $found = $repository->findBetween($artistic, new \DateTimeImmutable('2025-08-01'), new \DateTimeImmutable('2025-08-31'));

        self::assertSame(['2025-08-04', '2025-08-05'], array_keys($found));
        self::assertCount(1, $found['2025-08-04']->getMarks());
        self::assertSame('2025-08-04', $repository->findOne($artistic, new \DateTimeImmutable('2025-08-04 13:00'))?->getDate()->format('Y-m-d'));
        self::assertNull($repository->findOne($artistic, new \DateTimeImmutable('2025-08-06')));

        $onFourth = $repository->findForSchoolOn($this->em->find(School::class, $this->school->getId()), $this->em->find(SchoolYear::class, $this->sy->getId()), new \DateTimeImmutable('2025-08-04'));
        self::assertEqualsCanonicalizing([$this->artistic->getId(), $this->honest->getId()], array_keys($onFourth));

        $school = $this->em->find(School::class, $this->school->getId());
        self::assertSame(2, $repository->countSectionsOn($school, [new \DateTimeImmutable('2025-08-04'), new \DateTimeImmutable('2025-08-05')]));
        self::assertSame(1, $repository->countSectionsOn($school, [new \DateTimeImmutable('2025-08-05')]));
        self::assertSame(0, $repository->countSectionsOn($school, []));
    }

    public function testUnenrollDeletesMarks(): void
    {
        $rollCall = $this->rollCall($this->artistic, '2025-08-04');
        $rollCall->replaceMarks([$this->enrollment->getId() => [$this->enrollment, LearnerMark::Absent]]);
        $this->em->flush();
        $rollCallId = $rollCall->getId();

        $this->em->createQuery('DELETE FROM '.Enrollment::class.' e WHERE e.id = :id')->setParameter('id', $this->enrollment->getId())->execute();
        $this->em->clear();

        self::assertSame(0, $this->em->getRepository(AttendanceMark::class)->count([]));
        self::assertNotNull($this->em->find(RollCall::class, $rollCallId));
    }

    public function testSubmissions(): void
    {
        $this->em->persist(new Sf2Submission($this->artistic, new \DateTimeImmutable('2025-08-17'), $this->adviser, new \DateTimeImmutable('2025-09-03 15:00')));
        $this->em->flush();
        $this->em->clear();

        $repository = static::getContainer()->get(Sf2SubmissionRepository::class);
        $artistic = $this->em->find(Section::class, $this->artistic->getId());
        $submission = $repository->findOne($artistic, new \DateTimeImmutable('2025-08-01'));

        self::assertSame('2025-08-01', $submission?->getMonth()->format('Y-m-d'));
        self::assertNull($repository->findOne($artistic, new \DateTimeImmutable('2025-09-01')));
        $forMonth = $repository->findForMonth($this->em->find(School::class, $this->school->getId()), $this->em->find(SchoolYear::class, $this->sy->getId()), new \DateTimeImmutable('2025-08-01'));
        self::assertSame([$this->artistic->getId()], array_keys($forMonth));
    }
}
