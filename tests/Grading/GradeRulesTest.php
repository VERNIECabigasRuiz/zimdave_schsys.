<?php

namespace App\Tests\Grading;

use App\Entity\Enrollment;
use App\Entity\Grade;
use App\Entity\GradeLevel;
use App\Entity\GradeLock;
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
use App\Grading\ClassGradeStatus;
use App\Grading\GradeRules;
use App\Grading\SheetLearner;
use App\Repository\EnrollmentRepository;
use App\Repository\GradeLockRepository;
use App\Repository\GradeRepository;
use App\Repository\GradeUnlockRepository;
use App\Repository\TransferRequestRepository;
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
        $this->term1 = $this->sy->getGradingPeriod(1);
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
            $c->get(GradeRepository::class),
            $c->get(GradeLockRepository::class),
            $c->get(GradeUnlockRepository::class),
            $c->get(EnrollmentRepository::class),
            $c->get(TransferRequestRepository::class),
        );
    }

    private function subject(School $school, SchoolYear $sy, GradeLevel $level, string $code, string $name, ?Subject $parent = null): Subject
    {
        $subject = (new Subject($school, $sy))->setGradeLevel($level)->setCode($code)->setName($name)->setParent($parent)->setPosition(1);
        if (null === $parent) {
            $subject->setMeetingsPerWeek(5);
        }
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function enrolled(string $last, string $first, SexAtBirth $sex = SexAtBirth::Female, ?Section $section = null): Enrollment
    {
        $learner = $this->learner((string) ++$this->lrn, $last, $first)->setSex($sex);

        return $this->enroll($learner, $this->sy, $section ?? $this->artistic, $this->teacher);
    }

    private function transfer(Enrollment $enrollment, Section $to, string $decidedAt): void
    {
        $request = new TransferRequest($enrollment, $to, $this->teacher, new \DateTimeImmutable($decidedAt.' 08:00'));
        $request->decide(TransferStatus::Approved, $this->teacher, new \DateTimeImmutable($decidedAt.' 10:00'));
        $enrollment->moveTo($to);
        $this->em->persist($request);
        $this->em->flush();
    }

    private function lock(): void
    {
        $this->em->persist(new GradeLock($this->school, $this->term1, $this->principal, new \DateTimeImmutable('2025-08-22 10:00')));
        $this->em->flush();
    }

    private function unlock(): void
    {
        $this->em->persist(new GradeUnlock($this->artistic, $this->math, $this->term1, $this->principal, new \DateTimeImmutable('2025-08-22 11:00')));
        $this->em->flush();
    }

    private function setOpen(bool $open): void
    {
        $this->term1->setOpen($open);
        $this->em->flush();
    }

    private function classStatus(): ClassGradeStatus
    {
        return $this->rules()->status($this->artistic, $this->math, $this->term1);
    }

    public function testStatusMatrix(): void
    {
        $this->setOpen(false);
        self::assertSame(ClassGradeStatus::Closed, $this->classStatus());
        $this->setOpen(true);
        self::assertSame(ClassGradeStatus::Open, $this->classStatus());

        $this->lock();
        self::assertSame(ClassGradeStatus::Locked, $this->classStatus());
        $this->setOpen(false);
        self::assertSame(ClassGradeStatus::Locked, $this->classStatus());

        $this->unlock();
        self::assertSame(ClassGradeStatus::Unlocked, $this->classStatus());
        // the admin closing the period doesn't take away a principal's unlock
        $this->setOpen(true);
        self::assertSame(ClassGradeStatus::Unlocked, $this->classStatus());
        $this->setOpen(false);
        self::assertSame(ClassGradeStatus::Unlocked, $this->classStatus());

        // another subject of the same class stays locked
        $science = $this->subject($this->school, $this->sy, GradeLevel::Grade8, 'SCI8', 'Science');
        self::assertSame(ClassGradeStatus::Locked, $this->rules()->status($this->artistic, $science, $this->term1));

        self::assertSame('Unlocked for corrections', ClassGradeStatus::Unlocked->label());
        self::assertSame('Open', ClassGradeStatus::Open->label());
        self::assertSame('Closed', ClassGradeStatus::Closed->label());
        self::assertSame('Locked', ClassGradeStatus::Locked->label());
        self::assertSame('unlocked', ClassGradeStatus::Unlocked->value);
    }

    public function testRefusalMessages(): void
    {
        $rules = $this->rules();

        self::assertSame('Term 1 is locked by the principal.', $rules->refusalToEdit(ClassGradeStatus::Locked, $this->term1));
        self::assertSame("Term 1 isn't open for grading.", $rules->refusalToEdit(ClassGradeStatus::Closed, $this->term1));
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
        self::assertTrue($rules->isGradableSubject($grade1, $this->subject($this->school, $this->sy, GradeLevel::Grade1, 'MATH1', 'Mathematics')));
        $grade10 = $this->newSection($this->school, $this->sy, GradeLevel::Grade10, 'Diamond');
        self::assertTrue($rules->isGradableSubject($grade10, $this->subject($this->school, $this->sy, GradeLevel::Grade10, 'MATH10', 'Mathematics')));

        $grade11 = $this->newSection($this->school, $this->sy, GradeLevel::Grade11, 'Einstein');
        self::assertFalse($rules->isGradableSubject($grade11, $this->subject($this->school, $this->sy, GradeLevel::Grade11, 'GENMATH', 'General Mathematics')));

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
        $dela->setRemarkCode(Sf1Remark::Dropped)->setRemarkDetails('b.1 Illness')->setRemarkDate(new \DateTimeImmutable('2025-08-15'));
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
            ['ARNOL, GERALD, -', 'BAÑEZ, REMUEL, -', 'CABAHUG, JOHN, -', 'ABAD, LORNA, -', 'CRUZ, ANA, -', 'DELA PEÑA, MARIE, -', 'DIAZ, PIA, -'],
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
        self::assertSame([$cruz, $banez, $dela], [$byName['Cruz']->enrollment, $byName['Bañez']->enrollment, $byName['Dela Peña']->enrollment]);

        // the section they moved to lists them as its own; Cabahug, who went back, as transferred out
        $otherSheet = $this->rules()->sheet($other);
        self::assertSame(['CABAHUG, JOHN, -', 'ZAMORA, LITO, -', 'ABAD, LORNA, -', 'DIAZ, PIA, -'], array_map(static fn (SheetLearner $l) => $l->name, $otherSheet));
        self::assertSame([false, 'Transferred out'], [$otherSheet[0]->editable, $otherSheet[0]->status]);
        self::assertSame([true, true, true], [$otherSheet[1]->editable, $otherSheet[2]->editable, $otherSheet[3]->editable]);
    }

    public function testSheetLeavesOutTransfersBeforeTheSchoolYear(): void
    {
        $other = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Diligence');
        $abad = $this->enrolled('Abad', 'Lorna');
        $this->transfer($abad, $other, '2025-05-20');

        self::assertSame([], $this->rules()->sheet($this->artistic));
    }

    public function testParentGrade(): void
    {
        $rules = $this->rules();
        $mapeh = $this->subject($this->school, $this->sy, GradeLevel::Grade8, 'MAPEH8', 'MAPEH');
        $music = $this->subject($this->school, $this->sy, GradeLevel::Grade8, 'MUS8', 'Music', $mapeh);
        $arts = $this->subject($this->school, $this->sy, GradeLevel::Grade8, 'ARTS8', 'Arts', $mapeh);

        self::assertSame(85, $rules->parentGrade($mapeh, [$music->getId() => 84, $arts->getId() => 85]));
        self::assertSame(84, $rules->parentGrade($mapeh, [$music->getId() => 84, $arts->getId() => 84]));
        self::assertNull($rules->parentGrade($mapeh, [$music->getId() => 80, $arts->getId() => null]));
        // a component missing from the array has no grade either
        self::assertNull($rules->parentGrade($mapeh, [$music->getId() => 80]));
        // grades of other subjects don't count
        self::assertSame(90, $rules->parentGrade($mapeh, [$music->getId() => 90, $arts->getId() => 90, $this->math->getId() => 60]));
        // a subject without components has no parent grade
        self::assertNull($rules->parentGrade($this->math, [$this->math->getId() => 90]));
    }

    public function testCanLock(): void
    {
        $rules = $this->rules();
        $this->setOpen(false);
        self::assertFalse($rules->canLock($this->school, $this->term1));

        $this->em->persist(new Grade($this->enrolled('Arnol', 'Gerald'), $this->math, $this->term1, 90, $this->teacher, new \DateTimeImmutable('2025-08-22 10:00')));
        $this->em->flush();
        self::assertTrue($rules->canLock($this->school, $this->term1));

        $this->setOpen(true);
        self::assertTrue($rules->canLock($this->school, $this->term1));
        $other = $this->school('303197', 'Other National High School');
        self::assertTrue($rules->canLock($other, $this->term1));
        $this->setOpen(false);
        self::assertFalse($rules->canLock($other, $this->term1));
    }
}
