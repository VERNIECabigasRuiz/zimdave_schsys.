<?php

namespace App\Tests\Grading;

use App\Entity\Enrollment;
use App\Entity\Grade;
use App\Entity\GradeLevel;
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
use App\Grading\GradeRules;
use App\Grading\GridColumn;
use App\Grading\SectionGradeGrid;
use App\Repository\EnrollmentRepository;
use App\Repository\GradeLockRepository;
use App\Repository\GradeRepository;
use App\Repository\GradeUnlockRepository;
use App\Repository\SubjectRepository;
use App\Repository\TeachingLoadRepository;
use App\Repository\TransferRequestRepository;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SectionGradeGridTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $honest;
    private User $teacher;
    private GradingPeriod $term1;
    private Subject $math;
    private Subject $mapeh;
    private Subject $music;
    private Subject $arts;
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
        $this->honest = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Honest');
        $this->math = $this->subject('MATH8', 'Mathematics', 1);
        $this->mapeh = $this->subject('MAPEH8', 'MAPEH', 2);
        $this->music = $this->subject('MUS8', 'Music', 1, $this->mapeh);
        $this->arts = $this->subject('ART8', 'Arts', 2, $this->mapeh);
        $this->em->persist(new TeachingLoad($this->honest, $this->math, $this->teacher));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function subject(string $code, string $name, int $position, ?Subject $parent = null): Subject
    {
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode($code)->setName($name)->setParent($parent)->setPosition($position);
        if (null === $parent) {
            $subject->setMeetingsPerWeek(5);
        }
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function enrolled(string $last, SexAtBirth $sex = SexAtBirth::Female): Enrollment
    {
        $learner = $this->learner((string) ++$this->lrn, $last, 'Ana')->setSex($sex);

        return $this->enroll($learner, $this->sy, $this->honest, $this->teacher);
    }

    private function grade(Enrollment $enrollment, Subject $subject, int $value): void
    {
        $this->em->persist(new Grade($enrollment, $subject, $this->term1, $value, $this->teacher, new \DateTimeImmutable('2025-08-21')));
        $this->em->flush();
    }

    private function grid(): SectionGradeGrid
    {
        $c = static::getContainer();
        $rules = new GradeRules(
            $c->get(GradeRepository::class),
            $c->get(GradeLockRepository::class),
            $c->get(GradeUnlockRepository::class),
            $c->get(EnrollmentRepository::class),
            $c->get(TransferRequestRepository::class),
        );

        return new SectionGradeGrid($c->get(SubjectRepository::class), $c->get(TeachingLoadRepository::class), $c->get(GradeRepository::class), $rules);
    }

    /** @param list<GridColumn> $columns */
    private static function byKey(array $columns): array
    {
        $byKey = [];
        foreach ($columns as $column) {
            $byKey[$column->key] = $column;
        }

        return $byKey;
    }

    public function testColumnsFollowReportCardOrderWithAnAverageColumnForAParent(): void
    {
        $this->enrolled('Abad');

        $grid = $this->grid()->build($this->honest, $this->term1);

        self::assertSame(
            ['s'.$this->math->getId(), 's'.$this->music->getId(), 's'.$this->arts->getId(), 'avg'.$this->mapeh->getId()],
            array_map(static fn (GridColumn $c) => $c->key, $grid['columns']),
        );
        self::assertSame(['Mathematics', 'Music', 'Arts', 'MAPEH'], array_map(static fn (GridColumn $c) => $c->label, $grid['columns']));
        self::assertSame([false, false, false, true], array_map(static fn (GridColumn $c) => $c->average, $grid['columns']));
        self::assertSame($this->teacher->getFullName(), $grid['columns'][0]->teacherName);
        self::assertNull($grid['columns'][1]->teacherName);
    }

    public function testCellsAreBlankUntilGradedAndTheAverageNeedsEveryComponent(): void
    {
        $abad = $this->enrolled('Abad');
        $bautista = $this->enrolled('Bautista', SexAtBirth::Male);
        $this->grade($abad, $this->math, 90);
        $this->grade($abad, $this->music, 85);
        $this->grade($abad, $this->arts, 90);
        $this->grade($bautista, $this->music, 80);

        $grid = $this->grid()->build($this->honest, $this->term1);

        // males first
        self::assertStringStartsWith('BAUTISTA', $grid['rows'][0]['learner']->name);
        $male = $grid['rows'][0]['cells'];
        $female = $grid['rows'][1]['cells'];
        self::assertNull($male['s'.$this->math->getId()]);
        self::assertSame(80, $male['s'.$this->music->getId()]);
        self::assertNull($male['avg'.$this->mapeh->getId()]);
        self::assertSame(90, $female['s'.$this->math->getId()]);
        self::assertSame(88, $female['avg'.$this->mapeh->getId()]);
    }

    public function testColumnsCountGradedAndListedForEditableLearnersOnly(): void
    {
        $abad = $this->enrolled('Abad');
        $bautista = $this->enrolled('Bautista', SexAtBirth::Male);
        $dropped = $this->enrolled('Cruz');
        $dropped->setRemarkCode(Sf1Remark::Dropped);
        $this->em->flush();
        $this->grade($abad, $this->math, 90);
        $this->grade($dropped, $this->math, 75);
        $this->grade($abad, $this->music, 85);
        $this->grade($abad, $this->arts, 90);
        $this->grade($bautista, $this->music, 80);

        $grid = $this->grid()->build($this->honest, $this->term1);
        $byKey = self::byKey($grid['columns']);

        self::assertSame([1, 2], [$byKey['s'.$this->math->getId()]->graded, $byKey['s'.$this->math->getId()]->listed]);
        self::assertSame([2, 2], [$byKey['s'.$this->music->getId()]->graded, $byKey['s'.$this->music->getId()]->listed]);
        self::assertSame([1, 2], [$byKey['avg'.$this->mapeh->getId()]->graded, $byKey['avg'.$this->mapeh->getId()]->listed]);
        // the dropped learner stays on the grid with the grade they have
        $cruz = array_values(array_filter($grid['rows'], static fn (array $r) => str_starts_with($r['learner']->name, 'CRUZ')))[0];
        self::assertFalse($cruz['learner']->editable);
        self::assertSame(75, $cruz['cells']['s'.$this->math->getId()]);
    }

    public function testATransferredOutLearnerKeepsTheGradesOfThisSection(): void
    {
        $abad = $this->enrolled('Abad');
        $this->grade($abad, $this->math, 90);
        $this->grade($abad, $this->music, 85);
        $this->grade($abad, $this->arts, 90);
        // as TransferManager::approve does: the same enrollment row moves to the receiving section
        $to = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Brilliant');
        $request = new TransferRequest($abad, $to, $this->teacher, new \DateTimeImmutable('2025-08-22 08:00'));
        $request->decide(TransferStatus::Approved, $this->teacher, new \DateTimeImmutable('2025-08-22 09:00'));
        $abad->moveTo($to);
        $this->em->persist($request);
        $this->em->flush();

        $grid = $this->grid()->build($this->honest, $this->term1);

        self::assertCount(1, $grid['rows']);
        $row = $grid['rows'][0];
        self::assertSame([false, 'Transferred out'], [$row['learner']->editable, $row['learner']->status]);
        self::assertSame(90, $row['cells']['s'.$this->math->getId()]);
        self::assertSame(88, $row['cells']['avg'.$this->mapeh->getId()]);
        self::assertSame([0, 0], [self::byKey($grid['columns'])['s'.$this->math->getId()]->graded, self::byKey($grid['columns'])['s'.$this->math->getId()]->listed]);
    }
}
