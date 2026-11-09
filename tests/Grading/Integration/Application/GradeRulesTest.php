<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Application;

use App\Learners\Application\Api\SectionRosterInterface;
use App\Learners\Domain\Model\Enrollment;
use App\Grading\Domain\Model\Grade;
use App\Grading\Domain\Model\GradeOpening;
use App\Schools\Domain\Model\GradeLevel;
use App\Grading\Domain\Model\GradeLock;
use App\Grading\Domain\Model\GradeUnlock;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\SexAtBirth;
use App\Learners\Domain\Model\Sf1Remark;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Learners\Domain\Model\TransferRequest;
use App\Learners\Domain\Model\TransferStatus;
use App\Access\Domain\Model\User;
use App\Grading\Domain\Model\ClassGradeStatus;
use App\Grading\Application\Service\GradeRules;
use App\Grading\Application\Service\SheetLearner;
use App\Grading\Domain\Model\Repository\GradeLockRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeOpeningRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeRepositoryInterface;
use App\Grading\Domain\Model\Repository\GradeUnlockRepositoryInterface;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class GradeRulesTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private User $teacher;
    private User $principal;
    private Subject $math;
    private GradingPeriod $term1;
    private int $lrn = 120017170000;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2025-08-22 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->sy->rebuildGradingPeriods();
        $this->em->flush();
        $this->term1 = $this->sy->getGradingPeriod(1) ?? throw new \LogicException('No period 1');
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->teacher = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->principal = $this->plainUser('rosa', User::ROLE_PRINCIPAL);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic');
        $this->math = $this->subject($this->school, $this->sy, GradeLevel::Grade8, 'MATH8', 'Mathematics');
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
            $c->get(GradeRepositoryInterface::class),
            $c->get(GradeLockRepositoryInterface::class),
            $c->get(GradeUnlockRepositoryInterface::class),
            $c->get(SectionRosterInterface::class),
            $c->get(GradeOpeningRepositoryInterface::class),
        );
    }

    private function subject(
        School $school,
        SchoolYear $sy,
        GradeLevel $level,
        string $code,
        string $name,
        ?Subject $parent = null,
    ): Subject {
        $subject = (new Subject($school, $sy))
            ->setGradeLevel($level)
            ->setCode($code)
            ->setName($name)
            ->setParent($parent)
            ->setPosition(1);
        if (null === $parent) {
            $subject->setMeetingsPerWeek(5);
        }
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function enrolled(
        string $last,
        string $first,
        SexAtBirth $sex = SexAtBirth::Female,
        ?Section $section = null,
    ): Enrollment {
        $learner = $this->learner((string) ++$this->lrn, $last, $first)->setSex($sex);

        return $this->enroll($learner, $this->sy, $section ?? $this->artistic, $this->teacher);
    }

    private function transfer(Enrollment $enrollment, Section $to, string $decidedAt): void
    {
        $request = new TransferRequest($enrollment, $to, $this->teacher, new \DateTimeImmutable($decidedAt . ' 08:00'));
        $request->decide(TransferStatus::Approved, $this->teacher, new \DateTimeImmutable($decidedAt . ' 10:00'));
        $enrollment->moveTo($to);
        $this->em->persist($request);
        $this->em->flush();
    }

    private function lock(): void
    {
        $this->em->persist(
            new GradeLock($this->school, $this->term1, $this->principal, new \DateTimeImmutable('2025-08-22 10:00')),
        );
        $this->em->flush();
    }

    private function unlock(): void
    {
        $this->em->persist(
            new GradeUnlock(
                $this->artistic,
                $this->math,
                $this->term1,
                $this->principal,
                new \DateTimeImmutable('2025-08-22 11:00'),
            ),
        );
        $this->em->flush();
    }

    /** Opens or closes Term 1 for grading at the school (this one unless given), as its principal would. */
    private function setGradingOpen(bool $open, ?School $school = null): void
    {
        $school ??= $this->school;
        $opening = $this->em->getRepository(GradeOpening::class)->findOneBy(
            ['school' => $school, 'gradingPeriod' => $this->term1],
        );
        if ($open && null === $opening) {
            $this->openGrading($school, $this->term1);
        } elseif (!$open && null !== $opening) {
            $this->em->remove($opening);
            $this->em->flush();
        }
    }

    private function classStatus(): ClassGradeStatus
    {
        return $this->rules()->status($this->artistic, $this->math, $this->term1);
    }

    public function testStatusMatrix(): void
    {
        $this->setGradingOpen(false);
        self::assertSame(ClassGradeStatus::Closed, $this->classStatus());
        $this->setGradingOpen(true);
        self::assertSame(ClassGradeStatus::Open, $this->classStatus());

        $this->lock();
        self::assertSame(ClassGradeStatus::Locked, $this->classStatus());
        $this->setGradingOpen(false);
        self::assertSame(ClassGradeStatus::Locked, $this->classStatus());

        $this->unlock();
        self::assertSame(ClassGradeStatus::Unlocked, $this->classStatus());
        // the school closing the period doesn't take away a principal's unlock
        $this->setGradingOpen(true);
        self::assertSame(ClassGradeStatus::Unlocked, $this->classStatus());
        $this->setGradingOpen(false);
        self::assertSame(ClassGradeStatus::Unlocked, $this->classStatus());

        // another subject of the same class stays locked
        $science = $this->subject($this->school, $this->sy, GradeLevel::Grade8, 'SCI8', 'Science');
        self::assertSame(ClassGradeStatus::Locked, $this->rules()->status($this->artistic, $science, $this->term1));

        self::assertSame('Unlocked for corrections', ClassGradeStatus::Unlocked->label());
        self::assertSame('Open', ClassGradeStatus::Open->label());
        self::assertSame('Closed', ClassGradeStatus::Closed->label());
        self::assertSame('Locked', ClassGradeStatus::Locked->label());
        // the pages pick badge colours by these values
        self::assertSame('"unlocked"', json_encode(ClassGradeStatus::Unlocked));
    }

    public function testStatusFromLoadedLocks(): void
    {
        self::assertSame(ClassGradeStatus::Closed, GradeRules::statusFor(false, locked: false, unlocked: false));
        self::assertSame(ClassGradeStatus::Locked, GradeRules::statusFor(false, locked: true, unlocked: false));
        self::assertSame(ClassGradeStatus::Unlocked, GradeRules::statusFor(false, locked: true, unlocked: true));
        // an unlock without the school's lock means nothing
        self::assertSame(ClassGradeStatus::Closed, GradeRules::statusFor(false, locked: false, unlocked: true));
        self::assertSame(ClassGradeStatus::Open, GradeRules::statusFor(true, locked: false, unlocked: true));
        self::assertSame(ClassGradeStatus::Locked, GradeRules::statusFor(true, locked: true, unlocked: false));
    }

    public function testOpenInOneSchoolOnly(): void
    {
        $other = $this->school('303197', 'Other National High School');
        $honest = $this->newSection($other, $this->sy, GradeLevel::Grade8, 'Honest');
        $otherMath = $this->subject($other, $this->sy, GradeLevel::Grade8, 'MATH8', 'Mathematics');
        $this->setGradingOpen(true);

        $rules = $this->rules();
        self::assertTrue($rules->isOpenFor($this->school, $this->term1));
        self::assertFalse($rules->isOpenFor($other, $this->term1));
        self::assertSame(ClassGradeStatus::Open, $this->classStatus());
        self::assertSame(ClassGradeStatus::Closed, $rules->status($honest, $otherMath, $this->term1));
        self::assertTrue($rules->canLock($this->school, $this->term1));
        self::assertFalse($rules->canLock($other, $this->term1));
    }

    public function testRefusalMessages(): void
    {
        $rules = $this->rules();

        self::assertSame(
            'Term 1 is locked by the principal.',
            $rules->refusalToEdit(ClassGradeStatus::Locked, $this->term1),
        );
        self::assertSame(
            "Term 1 isn't open for grading.",
            $rules->refusalToEdit(ClassGradeStatus::Closed, $this->term1),
        );
        self::assertNull($rules->refusalToEdit(ClassGradeStatus::Open, $this->term1));
        self::assertNull($rules->refusalToEdit(ClassGradeStatus::Unlocked, $this->term1));
    }

    public function testGradable(): void
    {
        $rules = $this->rules();
        $mapeh = $this->subject($this->school, $this->sy, GradeLevel::Grade8, 'MAPEH8', 'MAPEH');
        $mapeh->setMeetingsPerWeek(null);
        $music = $this->subject($this->school, $this->sy, GradeLevel::Grade8, 'MUS8', 'Music', $mapeh);

        self::assertTrue($rules->isGradableSubject($this->artistic, $music));
        self::assertTrue($rules->isGradableSubject($this->artistic, $this->math));
        self::assertFalse($rules->isGradableSubject($this->artistic, $mapeh));
        self::assertTrue($rules->isGradable(new TeachingLoad($this->artistic, $music, $this->teacher)));
        self::assertFalse($rules->isGradable(new TeachingLoad($this->artistic, $mapeh, $this->teacher)));

        $kinder = $this->newSection($this->school, $this->sy, GradeLevel::Kinder, 'Sampaguita');
        $kinderSubject = $this->subject($this->school, $this->sy, GradeLevel::Kinder, 'K', 'Kindergarten');
        self::assertFalse($rules->isGradableSubject($kinder, $kinderSubject));
        self::assertFalse($rules->isGradable(new TeachingLoad($kinder, $kinderSubject, $this->teacher)));

        $grade1 = $this->newSection($this->school, $this->sy, GradeLevel::Grade1, 'Rose');
        self::assertTrue(
            $rules->isGradableSubject(
                $grade1,
                $this->subject($this->school, $this->sy, GradeLevel::Grade1, 'MATH1', 'Mathematics'),
            ),
        );
        $grade10 = $this->newSection($this->school, $this->sy, GradeLevel::Grade10, 'Diamond');
        self::assertTrue(
            $rules->isGradableSubject(
                $grade10,
                $this->subject($this->school, $this->sy, GradeLevel::Grade10, 'MATH10', 'Mathematics'),
            ),
        );

        $grade11 = $this->newSection($this->school, $this->sy, GradeLevel::Grade11, 'Einstein');
        self::assertFalse(
            $rules->isGradableSubject(
                $grade11,
                $this->subject($this->school, $this->sy, GradeLevel::Grade11, 'GENMATH', 'General Mathematics'),
            ),
        );

        $lastYear = $this->schoolYear(2024, current: false);
        $old = $this->newSection($this->school, $lastYear, GradeLevel::Grade8, 'Artistic');
        $oldMath = $this->subject($this->school, $lastYear, GradeLevel::Grade8, 'MATH8', 'Mathematics');
        self::assertFalse($rules->isGradableSubject($old, $oldMath));
        self::assertFalse($rules->isGradable(new TeachingLoad($old, $oldMath, $this->teacher)));
    }

    public function testSheetListsDroppedAndTransferredOutReadOnly(): void
    {
        $other = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Diligence');
        $cruz = $this->enrolled('Cruz', 'Ana');
        $arnol = $this->enrolled('Arnol', 'Gerald', SexAtBirth::Male);
        $banez = $this->enrolled('Bañez', 'Remuel', SexAtBirth::Male);
        $dela = $this->enrolled('Dela Peña', 'Marie');
        $dela->setRemarkCode(Sf1Remark::Dropped)->setRemarkDetails('b.1 Illness')->setRemarkDate(
            new \DateTimeImmutable('2025-08-15'),
        );
        $this->em->flush();
        $abad = $this->enrolled('Abad', 'Lorna');
        $this->transfer($abad, $other, '2025-08-11');
        // moved out and back: on the class list once, editable
        $cabahug = $this->enrolled('Cabahug', 'John', SexAtBirth::Male);
        $this->transfer($cabahug, $other, '2025-08-04');
        $this->transfer($cabahug, $this->artistic, '2025-08-06');
        // out twice: listed once
        $diaz = $this->enrolled('Diaz', 'Pia');
        $this->transfer($diaz, $other, '2025-07-01');
        $this->transfer($diaz, $this->artistic, '2025-07-08');
        $this->transfer($diaz, $other, '2025-08-12');
        // a learner of another section isn't listed
        $this->enrolled('Zamora', 'Lito', SexAtBirth::Male, $other);

        $sheet = $this->rules()->sheet($this->artistic);

        self::assertContainsOnlyInstancesOf(SheetLearner::class, $sheet);
        self::assertSame(
            [
                'ARNOL, GERALD, -',
                'BAÑEZ, REMUEL, -',
                'CABAHUG, JOHN, -',
                'ABAD, LORNA, -',
                'CRUZ, ANA, -',
                'DELA PEÑA, MARIE, -',
                'DIAZ, PIA, -',
            ],
            array_map(static fn (SheetLearner $l) => $l->name, $sheet),
        );
        $byName = [];
        foreach ($sheet as $learner) {
            $byName[$learner->enrollment->getLearner()->getLastName()] = $learner;
        }

        self::assertSame([true, null], [$byName['Arnol']->editable, $byName['Arnol']->status]);
        self::assertTrue($byName['Arnol']->male);
        self::assertSame($arnol->getLearner()->getLrn(), $byName['Arnol']->lrn);
        self::assertSame($arnol, $byName['Arnol']->enrollment);
        self::assertSame([true, null], [$byName['Bañez']->editable, $byName['Bañez']->status]);
        self::assertSame([true, null], [$byName['Cabahug']->editable, $byName['Cabahug']->status]);
        self::assertSame([true, null], [$byName['Cruz']->editable, $byName['Cruz']->status]);
        self::assertFalse($byName['Cruz']->male);
        self::assertSame([false, 'Dropped'], [$byName['Dela Peña']->editable, $byName['Dela Peña']->status]);
        self::assertSame([false, 'Transferred out'], [$byName['Abad']->editable, $byName['Abad']->status]);
        self::assertSame($abad, $byName['Abad']->enrollment);
        self::assertSame([false, 'Transferred out'], [$byName['Diaz']->editable, $byName['Diaz']->status]);
        self::assertSame(
            [$cruz, $banez, $dela],
            [$byName['Cruz']->enrollment, $byName['Bañez']->enrollment, $byName['Dela Peña']->enrollment],
        );

        // the section they moved to lists them as its own; Cabahug, who went back, as transferred out
        $otherSheet = $this->rules()->sheet($other);
        self::assertSame(
            ['CABAHUG, JOHN, -', 'ZAMORA, LITO, -', 'ABAD, LORNA, -', 'DIAZ, PIA, -'],
            array_map(static fn (SheetLearner $l) => $l->name, $otherSheet),
        );
        self::assertSame([false, 'Transferred out'], [$otherSheet[0]->editable, $otherSheet[0]->status]);
        self::assertSame(
            [true, true, true],
            [$otherSheet[1]->editable, $otherSheet[2]->editable, $otherSheet[3]->editable],
        );
    }

    public function testSheetLeavesOutTransfersBeforeTheSchoolYear(): void
    {
        $other = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Diligence');
        $abad = $this->enrolled('Abad', 'Lorna');
        $this->transfer($abad, $other, '2025-05-20');

        self::assertSame([], $this->rules()->sheet($this->artistic));
    }

    public function testSheetCountsTransfersFromJuneOfAYearWithoutAStartDate(): void
    {
        // Initialize
        $other = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Diligence');
        $before = $this->enrolled('Abad', 'Lorna');
        $this->transfer($before, $other, '2025-05-20');
        $after = $this->enrolled('Bautista', 'Rina');
        $this->transfer($after, $other, '2025-08-14');
        // in memory only: the column can't be empty
        $this->sy->setStartDate(null);

        // Execute
        $sheet = $this->rules()->sheet($this->artistic);

        // Assert
        self::assertSame([$after], array_map(static fn (SheetLearner $l) => $l->enrollment, $sheet));
    }

    public function testParentGrade(): void
    {
        $rules = $this->rules();
        $mapeh = $this->subject($this->school, $this->sy, GradeLevel::Grade8, 'MAPEH8', 'MAPEH');
        $music = $this->subject($this->school, $this->sy, GradeLevel::Grade8, 'MUS8', 'Music', $mapeh);
        $arts = $this->subject($this->school, $this->sy, GradeLevel::Grade8, 'ARTS8', 'Arts', $mapeh);

        self::assertSame(85, $rules->parentGrade($mapeh, [(int) $music->getId() => 84, (int) $arts->getId() => 85]));
        self::assertSame(84, $rules->parentGrade($mapeh, [(int) $music->getId() => 84, (int) $arts->getId() => 84]));
        self::assertNull($rules->parentGrade($mapeh, [(int) $music->getId() => 80, (int) $arts->getId() => null]));
        // a component missing from the array has no grade either
        self::assertNull($rules->parentGrade($mapeh, [(int) $music->getId() => 80]));
        // grades of other subjects don't count
        self::assertSame(
            90,
            $rules->parentGrade($mapeh, [
                (int) $music->getId() => 90,
                (int) $arts->getId() => 90,
                (int) $this->math->getId() => 60,
            ]),
        );
        // a subject without components has no parent grade
        self::assertNull($rules->parentGrade($this->math, [(int) $this->math->getId() => 90]));
    }

    public function testCanLock(): void
    {
        $rules = $this->rules();
        $this->setGradingOpen(false);
        self::assertFalse($rules->canLock($this->school, $this->term1));

        $this->em->persist(
            new Grade(
                $this->enrolled('Arnol', 'Gerald'),
                $this->math,
                $this->term1,
                90,
                $this->teacher,
                new \DateTimeImmutable('2025-08-22 10:00'),
            ),
        );
        $this->em->flush();
        self::assertTrue($rules->canLock($this->school, $this->term1));

        // a school without grades can lock only while it has the period open
        $other = $this->school('303197', 'Other National High School');
        self::assertFalse($rules->canLock($other, $this->term1));
        $this->setGradingOpen(true, $other);
        self::assertTrue($rules->canLock($other, $this->term1));
        $this->setGradingOpen(false, $other);
        self::assertFalse($rules->canLock($other, $this->term1));
    }
}
