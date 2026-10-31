<?php

namespace App\Tests\CardSlip;

use App\Attendance\SchoolCalendar;
use App\Attendance\Sf2Roster;
use App\CardSlip\CardSlip;
use App\CardSlip\CardSlipBuilder;
use App\CardSlip\CardSlipComments;
use App\CardSlip\CardSlipLine;
use App\CardSlip\CardSlipSuggester;
use App\CardSlip\TermAttendance;
use App\Entity\CardSlipComment;
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
use App\Entity\TransferRequest;
use App\Entity\TransferStatus;
use App\Entity\User;
use App\Grading\GradeRecorder;
use App\Grading\SectionGradeGrid;
use App\Repository\CardSlipCommentRepository;
use App\Repository\EnrollmentRepository;
use App\Repository\GradeLockRepository;
use App\Repository\RollCallRepository;
use App\Repository\SchoolDayOffRepository;
use App\Repository\SubjectRepository;
use App\Repository\TransferRequestRepository;
use App\Service\SchoolClock;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/**
 * Grade 7 – Active: Filipino, Mathematics, then MAPEH with Music, Arts, PE and Health.
 * School year 2025-2026 in terms; term 1 runs Jun 1 – Sep 29, 2025, and it is Aug 22, 2025.
 */
class CardSlipBuilderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $section;
    private User $adviser;
    private GradingPeriod $term1;
    private GradingPeriod $term2;
    /** @var array<string, Subject> */
    private array $s = [];
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
        $this->term2 = $this->sy->getGradingPeriod(2);
        $this->school = $this->school('303196', 'Pagsabungan National High School')->setAddress('Pagsabungan, Mandaue City');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER)->setFullName('Dave Sosmeña');
        $this->section = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Active')->setAdviser($this->adviser);
        $this->s['Filipino'] = $this->subject('FIL7', 'Filipino', 1);
        $this->s['Mathematics'] = $this->subject('MATH7', 'Mathematics', 2);
        $this->s['MAPEH'] = $this->subject('MAPEH7', 'MAPEH', 3);
        $this->s['Music'] = $this->subject('MUS7', 'Music', 1, $this->s['MAPEH']);
        $this->s['Arts'] = $this->subject('ART7', 'Arts', 2, $this->s['MAPEH']);
        $this->s['PE'] = $this->subject('PE7', 'PE', 3, $this->s['MAPEH']);
        $this->s['Health'] = $this->subject('HEA7', 'Health', 4, $this->s['MAPEH']);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testGradesInReportCardOrderWithMapeh(): void
    {
        $abad = $this->enrolled('Abad');
        $this->grades($abad, ['Filipino' => 88, 'Mathematics' => 74, 'Music' => 85, 'Arts' => 88, 'PE' => 90, 'Health' => 86]);

        $slip = $this->slip($abad);

        self::assertEquals([
            new CardSlipLine('Filipino', 88, 'Passed', null),
            new CardSlipLine('Mathematics', 74, 'Failed', null),
            new CardSlipLine('MAPEH', 87, 'Passed', 'Music 85 · Arts 88 · PE 90 · Health 86'),
        ], $slip->lines);
        // (88 + 74 + 87) / 3 = 83: MAPEH counts once, its components don't
        self::assertSame(83.0, $slip->average);
        self::assertSame('83.00', $slip->averageText());
        self::assertSame('Benchmarking', $slip->descriptor);
        self::assertSame('Pagsabungan National High School', $slip->schoolName);
        self::assertSame('Pagsabungan, Mandaue City', $slip->schoolAddress);
        self::assertSame('Term 1', $slip->periodLabel);
        self::assertSame('2025-2026', $slip->schoolYear);
        self::assertSame('Grade 7 – ACTIVE', $slip->gradeSection);
        self::assertSame('Dave Sosmeña', $slip->adviserName);
        self::assertStringStartsWith('ABAD', $slip->learnerName);
        self::assertSame($abad->getLearner()->getLrn(), $slip->lrn);
        self::assertSame($abad->getId(), $slip->enrollmentId);
        // on the roster since June, no roll call marked anyone absent: every school day so far is present
        self::assertNotNull($slip->attendance);
        self::assertSame(0, $slip->attendance->absent);
        self::assertSame($slip->attendance->schoolDays, $slip->attendance->present);
    }

    #[DataProvider('averages')]
    public function testAverageAndDescriptor(float $average, string $descriptor, string $text): void
    {
        self::assertSame($descriptor, CardSlipBuilder::descriptor($average));
        self::assertSame($text, $this->dto($average)->averageText());
    }

    public static function averages(): iterable
    {
        yield [89.50, 'Advancing', '89.50'];
        yield [89.49, 'Benchmarking', '89.49'];
        yield [74.50, 'Connecting', '74.50'];
        yield [74.49, 'Developing', '74.49'];
        yield [64.49, 'Emerging', '64.49'];
        yield [86.0, 'Benchmarking', '86.00'];
    }

    public function testMissingComponentMakesIncomplete(): void
    {
        $abad = $this->enrolled('Abad');
        $this->grades($abad, ['Filipino' => 88, 'Mathematics' => 90, 'Music' => 85, 'Arts' => 88, 'PE' => 90]);

        $slip = $this->slip($abad);

        self::assertEquals(new CardSlipLine('MAPEH', null, '', 'Music 85 · Arts 88 · PE 90 · Health —'), $slip->lines[2]);
        self::assertNull($slip->average);
        self::assertNull($slip->descriptor);
        self::assertSame('Incomplete', $slip->averageText());
        self::assertContains('incomplete', $slip->suggestedCodes);
        self::assertContains(CardSlipComments::text('incomplete'), $slip->comments);
    }

    public function testRemarks(): void
    {
        $abad = $this->enrolled('Abad');
        $this->grades($abad, ['Filipino' => 75, 'Mathematics' => 74]);

        $lines = $this->slip($abad)->lines;

        self::assertSame(['Passed', 'Failed', ''], array_map(static fn (CardSlipLine $l) => $l->remarks, $lines));
        self::assertSame([75, 74, null], array_map(static fn (CardSlipLine $l) => $l->grade, $lines));
    }

    public function testDroppedAndTransferredOutExcluded(): void
    {
        $abad = $this->enrolled('Abad');
        $bautista = $this->enrolled('Bautista', SexAtBirth::Male);
        $cruz = $this->enrolled('Cruz');
        $cruz->setRemarkCode(Sf1Remark::Dropped);
        $diaz = $this->enrolled('Diaz');
        $to = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Brilliant');
        $request = new TransferRequest($diaz, $to, $this->adviser, new \DateTimeImmutable('2025-08-20 08:00'));
        $request->decide(TransferStatus::Approved, $this->adviser, new \DateTimeImmutable('2025-08-20 09:00'));
        $diaz->moveTo($to);
        $this->em->persist($request);
        $this->em->flush();

        $slips = $this->builder()->forSection($this->section, $this->term1);

        // males first
        self::assertSame([$bautista->getId(), $abad->getId()], array_map(static fn (CardSlip $s) => $s->enrollmentId, $slips));
        self::assertNull($this->builder()->forEnrollment($this->section, $this->term1, (int) $cruz->getId()));
        self::assertNull($this->builder()->forEnrollment($this->section, $this->term1, (int) $diaz->getId()));
    }

    public function testDraftUntilLocked(): void
    {
        $abad = $this->enrolled('Abad');
        self::assertTrue($this->slip($abad)->draft);

        $this->openGrading($this->school, $this->term1);
        static::getContainer()->get(GradeRecorder::class)->lockPeriod($this->school, $this->term1, $this->adviser);

        self::assertFalse($this->slip($abad)->draft);
    }

    public function testSavedCommentsOverrideSuggestions(): void
    {
        $abad = $this->enrolled('Abad');
        $this->grades($abad, ['Filipino' => 95, 'Mathematics' => 95, 'Music' => 95, 'Arts' => 95, 'PE' => 95, 'Health' => 95]);
        // a stale code from an older catalog is skipped
        $this->saveComment($abad, ['participates', 'retired_code', 'read_daily'], null);

        $slip = $this->slip($abad);

        self::assertSame(['participates', 'read_daily'], $slip->chosenCodes);
        self::assertSame([CardSlipComments::text('participates'), CardSlipComments::text('read_daily')], $slip->comments);
        self::assertContains('excellent', $slip->suggestedCodes);
    }

    public function testSuggestionsUsedWhenNothingSaved(): void
    {
        $abad = $this->enrolled('Abad');
        $this->grades($abad, ['Filipino' => 70, 'Mathematics' => 72, 'Music' => 85, 'Arts' => 88, 'PE' => 90, 'Health' => 86]);

        $slip = $this->slip($abad);

        self::assertSame($slip->suggestedCodes, $slip->chosenCodes);
        self::assertSame('needs_improvement', $slip->chosenCodes[0]);
        self::assertSame('Needs to improve in Filipino and Mathematics. Please attend remedial sessions.', $slip->comments[0]);
        self::assertNull($slip->customText);
    }

    public function testEmptySavedListStaysEmpty(): void
    {
        $abad = $this->enrolled('Abad');
        $this->saveComment($abad, [], null);

        $slip = $this->slip($abad);

        self::assertSame([], $slip->chosenCodes);
        self::assertSame([], $slip->comments);
        self::assertNotSame([], $slip->suggestedCodes);
    }

    public function testSavedNeedsImprovementDroppedWithoutAFailingSubject(): void
    {
        $abad = $this->enrolled('Abad');
        $this->grades($abad, ['Filipino' => 85, 'Mathematics' => 86, 'Music' => 85, 'Arts' => 88, 'PE' => 90, 'Health' => 86]);
        $this->saveComment($abad, ['needs_improvement', 'participates'], null);

        $slip = $this->slip($abad);

        self::assertSame(['participates'], $slip->chosenCodes);
        self::assertSame([CardSlipComments::text('participates')], $slip->comments);
        foreach ($slip->comments as $text) {
            self::assertStringNotContainsString('{subjects}', $text);
            self::assertStringNotContainsString('Needs to improve', $text);
        }
    }

    public function testSavedNeedsImprovementKeptWhileFailing(): void
    {
        $abad = $this->enrolled('Abad');
        $this->grades($abad, ['Filipino' => 70, 'Mathematics' => 86, 'Music' => 85, 'Arts' => 88, 'PE' => 90, 'Health' => 86]);
        $this->saveComment($abad, ['needs_improvement'], null);

        self::assertSame(['Needs to improve in Filipino. Please attend remedial sessions.'], $this->slip($abad)->comments);
    }

    public function testSavedIncompleteDroppedOnceAllGradesAreIn(): void
    {
        $abad = $this->enrolled('Abad');
        $this->saveComment($abad, ['incomplete', 'responsible'], null);
        self::assertSame(['incomplete', 'responsible'], $this->slip($abad)->chosenCodes);

        $this->grades($abad, ['Filipino' => 85, 'Mathematics' => 86, 'Music' => 85, 'Arts' => 88, 'PE' => 90, 'Health' => 86]);
        $slip = $this->slip($abad);

        self::assertSame(['responsible'], $slip->chosenCodes);
        self::assertNotContains(CardSlipComments::text('incomplete'), $slip->comments);
    }

    public function testCustomTextLast(): void
    {
        $abad = $this->enrolled('Abad');
        $this->saveComment($abad, ['responsible'], 'Won 2nd place in the division quiz bee.');

        $slip = $this->slip($abad);

        self::assertSame([CardSlipComments::text('responsible'), 'Won 2nd place in the division quiz bee.'], $slip->comments);
        self::assertSame('Won 2nd place in the division quiz bee.', $slip->customText);
    }

    public function testImprovedUsesPreviousTerm(): void
    {
        $abad = $this->enrolled('Abad');
        $bautista = $this->enrolled('Bautista', SexAtBirth::Male);
        $all = static fn (int $g) => ['Filipino' => $g, 'Mathematics' => $g, 'Music' => $g, 'Arts' => $g, 'PE' => $g, 'Health' => $g];
        $this->grades($abad, $all(80));
        $this->grades($abad, $all(84), $this->term2);
        $this->grades($bautista, $all(82));
        $this->grades($bautista, $all(84), $this->term2);

        self::assertContains('improved', $this->slip($abad, $this->term2)->suggestedCodes);
        self::assertNotContains('improved', $this->slip($bautista, $this->term2)->suggestedCodes);
        // term 1 has no previous term
        self::assertNotContains('improved', $this->slip($abad)->suggestedCodes);
    }

    private function subject(string $code, string $name, int $position, ?Subject $parent = null): Subject
    {
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setCode($code)->setName($name)->setParent($parent)->setPosition($position);
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
        $enrollment = new Enrollment($learner, $this->sy, $this->section, $this->adviser, new \DateTimeImmutable('2025-05-20 09:00'));
        $this->em->persist($enrollment);
        $this->em->flush();

        return $enrollment;
    }

    /** @param array<string, int> $grades by subject name */
    private function grades(Enrollment $enrollment, array $grades, ?GradingPeriod $period = null): void
    {
        foreach ($grades as $name => $value) {
            $this->em->persist(new Grade($enrollment, $this->s[$name], $period ?? $this->term1, $value, $this->adviser, new \DateTimeImmutable('2025-08-21')));
        }
        $this->em->flush();
    }

    /** @param list<string> $codes */
    private function saveComment(Enrollment $enrollment, array $codes, ?string $text): void
    {
        $comment = new CardSlipComment($enrollment, $this->term1);
        $comment->update($codes, $text, $this->adviser, new \DateTimeImmutable('2025-08-21'));
        $this->em->persist($comment);
        $this->em->flush();
    }

    private function slip(Enrollment $enrollment, ?GradingPeriod $period = null): CardSlip
    {
        $slip = $this->builder()->forEnrollment($this->section, $period ?? $this->term1, (int) $enrollment->getId());
        self::assertNotNull($slip);

        return $slip;
    }

    private function builder(): CardSlipBuilder
    {
        $c = static::getContainer();

        return new CardSlipBuilder(
            $c->get(SectionGradeGrid::class),
            $c->get(SubjectRepository::class),
            new TermAttendance(
                new Sf2Roster($c->get(EnrollmentRepository::class), $c->get(TransferRequestRepository::class)),
                new SchoolCalendar($c->get(SchoolDayOffRepository::class)),
                $c->get(RollCallRepository::class),
                new SchoolClock($c->get(ClockInterface::class), 'Asia/Manila'),
                $c->get(EnrollmentRepository::class),
            ),
            $c->get(CardSlipCommentRepository::class),
            $c->get(GradeLockRepository::class),
            new CardSlipSuggester(),
        );
    }

    private function dto(float $average): CardSlip
    {
        return new CardSlip('S', null, null, 'Term 1', '2025-2026', 'A', null, 'Grade 7 – ACTIVE', 'D', [], $average, CardSlipBuilder::descriptor($average), null, [], true, 1, [], [], null);
    }
}
