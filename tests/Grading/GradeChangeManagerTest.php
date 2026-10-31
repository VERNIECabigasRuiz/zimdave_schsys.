<?php

namespace App\Tests\Grading;

use App\Entity\ActivityLog;
use App\Entity\Enrollment;
use App\Entity\Grade;
use App\Entity\GradeChangeRequest;
use App\Entity\GradeChangeStatus;
use App\Entity\GradeLevel;
use App\Entity\GradeOpening;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Sf1Remark;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Grading\GradeChangeManager;
use App\Grading\GradeLocks;
use App\Grading\GradeRecorder;
use App\Grading\GradeRules;
use App\Grading\GradingException;
use App\Repository\EnrollmentRepository;
use App\Repository\GradeChangeRequestRepository;
use App\Repository\GradeLockRepository;
use App\Repository\GradeOpeningRepository;
use App\Repository\GradeRepository;
use App\Repository\GradeUnlockRepository;
use App\Repository\TransferRequestRepository;
use App\Service\ActivityLogger;
use App\Service\SchoolClock;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Lock\LockFactory;

class GradeChangeManagerTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private const string STALE = 'Grades have changed since this request was sent. Decline it so the teacher can send a new one.';
    private const string REASON = 'Misread the class record for these learners.';

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $honest;
    private User $teacher;
    private User $principal;
    private Subject $math;
    private TeachingLoad $load;
    private GradingPeriod $term1;
    /** @var list<Enrollment> Arnol, Bañez, Cruz */
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
            $learner = $this->learner((string) ++$this->lrn, $name, 'Learner')->setSex(SexAtBirth::Male);
            $this->enrolled[] = $this->enroll($learner, $this->sy, $this->honest, $this->teacher);
        }
        $this->em->flush();

        // Arnol 78, Bañez 85, Cruz ungraded; then the principal locks Term 1
        $this->openGrading($this->school, $this->term1);
        $this->recorder()->saveSheet($this->load, $this->term1, [$this->id(0) => 78, $this->id(1) => 85], $this->teacher);
        $this->recorder()->lockPeriod($this->school, $this->term1, $this->principal);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function rules(): GradeRules
    {
        $c = static::getContainer();

        return new GradeRules(
            $c->get(GradeRepository::class),
            $c->get(GradeLockRepository::class),
            $c->get(GradeUnlockRepository::class),
            $c->get(EnrollmentRepository::class),
            $c->get(TransferRequestRepository::class),
            $c->get(GradeOpeningRepository::class),
        );
    }

    private function clock(): SchoolClock
    {
        return new SchoolClock(static::getContainer()->get(ClockInterface::class), 'Asia/Manila');
    }

    private function recorder(): GradeRecorder
    {
        $c = static::getContainer();

        return new GradeRecorder(
            $this->em,
            new GradeLocks($c->get(LockFactory::class)),
            $this->rules(),
            $c->get(GradeRepository::class),
            $c->get(GradeLockRepository::class),
            $c->get(GradeUnlockRepository::class),
            $c->get(GradeOpeningRepository::class),
            $c->get(ActivityLogger::class),
            $this->clock(),
        );
    }

    private function manager(): GradeChangeManager
    {
        $c = static::getContainer();

        return new GradeChangeManager(
            $this->em,
            new GradeLocks($c->get(LockFactory::class)),
            $this->rules(),
            $c->get(GradeRepository::class),
            $c->get(GradeChangeRequestRepository::class),
            $c->get(ActivityLogger::class),
            $this->clock(),
        );
    }

    private function id(int $i): int
    {
        return (int) $this->enrolled[$i]->getId();
    }

    /** @param array<int, ?int> $values */
    private function request(array $values, string $reason = self::REASON, ?User $by = null): GradeChangeRequest
    {
        return $this->manager()->request($this->load, $this->term1, $values, $reason, $by ?? $this->teacher);
    }

    /** The usual request: Arnol 78 → 82, Cruz none → 90, Bañez unchanged. */
    private function usualRequest(): GradeChangeRequest
    {
        return $this->request([$this->id(0) => 82, $this->id(1) => 85, $this->id(2) => 90]);
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

    /** @return array<int, int> grade values by enrollment id, read back from the database */
    private function savedValues(): array
    {
        $values = [];
        foreach ($this->em->getRepository(Grade::class)->findAll() as $grade) {
            $this->em->refresh($grade);
            $values[$grade->getEnrollment()->getId()] = $grade->getValue();
        }
        ksort($values);

        return $values;
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

    private function statusInDb(GradeChangeRequest $request): GradeChangeStatus
    {
        $this->em->refresh($request);

        return $request->getStatus();
    }

    public function testRequestRecordsOnlyChangedLines(): void
    {
        $request = $this->request([$this->id(0) => 82, $this->id(1) => 85, $this->id(2) => 90], "  Misread the class record.  \n");

        $this->em->clear();
        $request = $this->em->find(GradeChangeRequest::class, $request->getId());
        self::assertNotNull($request);
        self::assertSame(GradeChangeStatus::Pending, $request->getStatus());
        self::assertSame('Misread the class record.', $request->getReason());
        self::assertSame($this->teacher->getId(), $request->getRequestedBy()->getId());
        $lines = array_map(static fn ($line) => [$line->getEnrollment()->getId(), $line->getFromValue(), $line->getToValue()], $request->getLines()->toArray());
        self::assertSame([[$this->id(0), 78, 82], [$this->id(2), null, 90]], array_values($lines));
        self::assertTrue($this->logged('Requested a change of Term 1 grades of Mathematics – Grade 8 – HONEST'));
        // nothing applied yet
        self::assertSame([$this->id(0) => 78, $this->id(1) => 85], $this->savedValues());
    }

    /** @return iterable<string, array{string, string}> */
    public static function refusals(): iterable
    {
        yield 'class open' => ['open', "Term 1 isn't locked — change the grades on the sheet."];
        yield 'class unlocked' => ['unlocked', "Term 1 isn't locked — change the grades on the sheet."];
        yield 'period closed' => ['closed', "Term 1 isn't open for grading."];
        yield 'pending exists' => ['pending', 'You already have a pending change request for this class.'];
        yield 'out of range' => ['59', 'Enter a grade from 60 to 100.'];
        yield 'nothing changed' => ['unchanged', 'Enter at least one corrected grade.'];
        yield 'short reason' => ['reason', 'Write the reason for the change (at least 10 characters).'];
        yield 'long reason' => ['long reason', 'Write the reason for the change (at least 10 characters).'];
        yield 'dropped learner only' => ['dropped', 'Enter at least one corrected grade.'];
    }

    #[DataProvider('refusals')]
    public function testRequestRefusals(string $case, string $message): void
    {
        $values = [$this->id(0) => 82];
        $reason = self::REASON;
        $before = 0;
        switch ($case) {
            case 'open':
                $this->recorder()->unlockPeriod($this->school, $this->term1);
                break;
            case 'unlocked':
                $this->recorder()->unlockClass($this->honest, $this->math, $this->term1, $this->principal);
                break;
            case 'closed':
                $this->recorder()->unlockPeriod($this->school, $this->term1);
                $this->em->remove($this->em->getRepository(GradeOpening::class)->findOneBy(['school' => $this->school]));
                $this->em->flush();
                break;
            case 'pending':
                $this->request([$this->id(1) => 88]);
                $before = 1;
                break;
            case '59':
                $values = [$this->id(0) => 82, $this->id(2) => 59];
                break;
            case 'unchanged':
                $values = [$this->id(0) => 78, $this->id(1) => 85, $this->id(2) => null];
                break;
            case 'reason':
                $reason = '   too short ';
                break;
            case 'long reason':
                $reason = str_repeat('a', 1001);
                break;
            case 'dropped':
                $this->enrolled[2]->setRemarkCode(Sf1Remark::Dropped);
                $this->em->flush();
                $values = [$this->id(2) => 90];
                break;
        }

        self::assertSame($message, $this->refusal(fn () => $this->request($values, $reason)));
        self::assertSame($before, $this->em->getRepository(GradeChangeRequest::class)->count([]));
        self::assertSame([$this->id(0) => 78, $this->id(1) => 85], $this->savedValues());
    }

    public function testRequestIgnoresDroppedLearnersValue(): void
    {
        $this->enrolled[2]->setRemarkCode(Sf1Remark::Dropped);
        $this->em->flush();

        $request = $this->request([$this->id(0) => 82, $this->id(2) => 90]);

        self::assertCount(1, $request->getLines());
        self::assertSame($this->id(0), $request->getLines()->first()->getEnrollment()->getId());
    }

    public function testApproveAppliesGrades(): void
    {
        $request = $this->usualRequest();
        static::mockTime('2025-08-23 09:00:00');

        $this->manager()->approve($request, $this->principal);

        self::assertSame([$this->id(0) => 82, $this->id(1) => 85, $this->id(2) => 90], $this->savedValues());
        $this->em->clear();
        foreach ([0, 2] as $i) {
            $grade = $this->em->getRepository(Grade::class)->findOneBy(['enrollment' => $this->id($i)]);
            self::assertSame($this->teacher->getId(), $grade->getUpdatedBy()->getId());
            self::assertSame('2025-08-23', $grade->getUpdatedAt()->format('Y-m-d'));
        }
        $request = $this->em->find(GradeChangeRequest::class, $request->getId());
        self::assertSame(GradeChangeStatus::Approved, $request->getStatus());
        self::assertSame($this->principal->getId(), $request->getDecidedBy()?->getId());
        self::assertNotNull($request->getDecidedAt());
        self::assertTrue($this->logged('Approved a change of Term 1 grades of Mathematics – Grade 8 – HONEST'));
    }

    public function testApproveRefusedWhenAGradeChanged(): void
    {
        $request = $this->usualRequest();
        $this->recorder()->unlockClass($this->honest, $this->math, $this->term1, $this->principal);
        $this->recorder()->saveSheet($this->load, $this->term1, [$this->id(0) => 80, $this->id(1) => 85], $this->teacher);

        self::assertSame(self::STALE, $this->refusal(fn () => $this->manager()->approve($request, $this->principal)));

        self::assertSame([$this->id(0) => 80, $this->id(1) => 85], $this->savedValues());
        self::assertSame(GradeChangeStatus::Pending, $this->statusInDb($request));
    }

    public function testApproveRefusedWhenALearnerDropped(): void
    {
        $request = $this->usualRequest();
        $this->enrolled[2]->setRemarkCode(Sf1Remark::Dropped);
        $this->em->flush();

        self::assertSame(self::STALE, $this->refusal(fn () => $this->manager()->approve($request, $this->principal)));

        self::assertSame([$this->id(0) => 78, $this->id(1) => 85], $this->savedValues());
        self::assertSame(GradeChangeStatus::Pending, $this->statusInDb($request));
    }

    public function testApproveWorksWhenPeriodUnlocked(): void
    {
        $request = $this->usualRequest();
        $this->recorder()->unlockPeriod($this->school, $this->term1);

        $this->manager()->approve($request, $this->principal);

        self::assertSame([$this->id(0) => 82, $this->id(1) => 85, $this->id(2) => 90], $this->savedValues());
        self::assertSame(GradeChangeStatus::Approved, $this->statusInDb($request));
    }

    public function testDeclineNeedsANote(): void
    {
        $request = $this->usualRequest();

        self::assertSame('Write a note for the teacher (at least 5 characters).', $this->refusal(fn () => $this->manager()->decline($request, $this->principal, ' ok ')));
        self::assertSame('Write a note for the teacher (at least 5 characters).', $this->refusal(fn () => $this->manager()->decline($request, $this->principal, str_repeat('a', 1001))));
        self::assertSame(GradeChangeStatus::Pending, $this->statusInDb($request));

        $this->manager()->decline($request, $this->principal, '  Attach the class record ');

        self::assertSame(GradeChangeStatus::Declined, $this->statusInDb($request));
        self::assertSame('Attach the class record', $request->getDecisionNote());
        self::assertSame($this->principal->getId(), $request->getDecidedBy()?->getId());
        self::assertSame([$this->id(0) => 78, $this->id(1) => 85], $this->savedValues());
        self::assertTrue($this->logged('Declined a change of Term 1 grades of Mathematics – Grade 8 – HONEST'));
        self::assertSame('This request has already been decided.', $this->refusal(fn () => $this->manager()->decline($request, $this->principal, 'Again, please')));
    }

    public function testWithdrawThenApproveRefused(): void
    {
        $request = $this->usualRequest();

        $this->manager()->withdraw($request, $this->teacher);

        self::assertSame(GradeChangeStatus::Withdrawn, $this->statusInDb($request));
        self::assertTrue($this->logged('Withdrew a change of Term 1 grades of Mathematics – Grade 8 – HONEST'));
        self::assertSame('This request has already been decided.', $this->refusal(fn () => $this->manager()->approve($request, $this->principal)));
        self::assertSame('This request has already been decided.', $this->refusal(fn () => $this->manager()->withdraw($request, $this->teacher)));
        self::assertSame([$this->id(0) => 78, $this->id(1) => 85], $this->savedValues());
        // the class is free for a new request
        self::assertTrue($this->request([$this->id(0) => 83])->isPending());
    }

    public function testApproveSeesAWithdrawalMadeElsewhere(): void
    {
        $request = $this->usualRequest();
        // the teacher withdraws in another request; this one's copy still says pending
        $this->em->getConnection()->executeStatement('UPDATE grade_change_request SET status = ? WHERE id = ?', [GradeChangeStatus::Withdrawn->value, $request->getId()]);
        self::assertTrue($request->isPending());

        self::assertSame('This request has already been decided.', $this->refusal(fn () => $this->manager()->approve($request, $this->principal)));
        self::assertSame([$this->id(0) => 78, $this->id(1) => 85], $this->savedValues());
    }

    public function testNewTeacherBlockedWhilePending(): void
    {
        $request = $this->usualRequest();
        $newTeacher = $this->plainUser('lina', User::ROLE_TEACHER);
        $this->load->setTeacher($newTeacher);
        $this->em->flush();

        self::assertSame('You already have a pending change request for this class.', $this->refusal(fn () => $this->request([$this->id(1) => 88], by: $newTeacher)));

        $this->manager()->withdraw($request, $this->teacher);
        self::assertSame(GradeChangeStatus::Withdrawn, $this->statusInDb($request));
    }
}
