<?php

namespace App\Tests\LessonPlan;

use App\Entity\AiDraft;
use App\Entity\GradeLevel;
use App\Entity\LessonPlan;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\LessonPlan\AiDraftFailed;
use App\LessonPlan\LessonPlanAi;
use App\Repository\AiDraftRepository;
use App\Service\SchoolClock;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class LessonPlanAiTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private User $tess;
    private FakeLessonPlanDrafter $fake;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-20 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->limitedPlan('With AI', 1000)->setAiDraftsPerTeacherMonthly(2);
        $this->em->flush();
        $this->school = $this->school('303196', 'Pagsabungan NHS', plan: 'With AI');
        $this->tess = $this->plainUser('tess', User::ROLE_TEACHER)->setFullName('Tess Reyes');
        $this->em->flush();
        $this->fake = new FakeLessonPlanDrafter();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function ai(): LessonPlanAi
    {
        $c = static::getContainer();

        return new LessonPlanAi($this->fake, $c->get(AiDraftRepository::class), $c->get(EntityManagerInterface::class), $c->get(SchoolClock::class));
    }

    private function lessonPlan(string $subjectName = 'Mathematics', ?string $holiday = 'Bonifacio Day'): LessonPlan
    {
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'ARTISTIC');
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('S8')->setName($subjectName)->setPosition(1);
        $this->em->persist($subject);
        $load = new TeachingLoad($section, $subject, $this->tess);
        $this->em->persist($load);
        $plan = (new LessonPlan($load, new \DateTimeImmutable('2026-11-30'), new \DateTimeImmutable()))
            ->setTopic('Multiplying monomials')
            ->setCompetencies("Multiplies simple monomials (M8AL-Ia-1)\nDivides monomials")
            ->setClassNotes('Many slow readers')
            ->setDays([LessonPlan::emptyDay('2026-11-30', $holiday), LessonPlan::emptyDay('2026-12-01'), LessonPlan::emptyDay('2026-12-02')]);
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    private function drafts(): int
    {
        return \count($this->em->getRepository(AiDraft::class)->findAll());
    }

    public function testDraftFillsEveryCellAndDeclaration(): void
    {
        $plan = $this->lessonPlan();

        $this->ai()->draft($plan, $this->tess);

        $day = $plan->getDays()[1];
        foreach (LessonPlan::DAY_FIELDS as $field) {
            self::assertSame("$field day 1 point 1\n$field day 1 point 2", $day[$field]);
        }
        self::assertSame("objectives day 2 point 1\nobjectives day 2 point 2", $plan->getDays()[2]['objectives']);
        self::assertSame("Mathematics 8 Learner's Module\nDepEd LR Portal", $plan->getReferences());
        self::assertStringContainsString('AI (Claude, fake-model) was used to draft', (string) $plan->getAiDeclaration());
        self::assertNotNull($plan->getDraftedAt());

        $request = $this->fake->lastRequest;
        self::assertSame(['2026-12-01', '2026-12-02'], $request->dates);
        self::assertSame('Multiplying monomials', $request->topic);
        self::assertSame(['Multiplies simple monomials (M8AL-Ia-1)', 'Divides monomials'], $request->competencies);
        self::assertSame('Grade 8', $request->grade);
        self::assertSame('Many slow readers', $request->classNotes);
        self::assertFalse($request->filipino);
    }

    public function testHolidayDaysStayEmpty(): void
    {
        $plan = $this->lessonPlan();

        $this->ai()->draft($plan, $this->tess);

        self::assertSame('Bonifacio Day', $plan->getDays()[0]['holiday']);
        self::assertSame('', $plan->getDays()[0]['objectives']);
    }

    public function testCountsOneDraft(): void
    {
        $plan = $this->lessonPlan();
        self::assertSame(2, $this->ai()->remaining($this->tess, $this->school));

        $this->ai()->draft($plan, $this->tess);

        self::assertSame(1, $this->drafts());
        self::assertSame(1, $this->ai()->remaining($this->tess, $this->school));
        $draft = $this->em->getRepository(AiDraft::class)->findAll()[0];
        self::assertSame([1200, 3400, 'fake-model'], [$draft->getInputTokens(), $draft->getOutputTokens(), $draft->getModel()]);
    }

    public function testLimitReachedRefusesWithoutCalling(): void
    {
        $plan = $this->lessonPlan();
        foreach ([1, 2] as $_) {
            $this->em->persist(new AiDraft($this->tess, $this->school, 'm', 1, 1, new \DateTimeImmutable('2026-11-05')));
        }
        $this->em->flush();

        try {
            $this->ai()->draft($plan, $this->tess);
            self::fail('Expected the limit to stop the draft.');
        } catch (AiDraftFailed $e) {
            self::assertSame('no AI drafts left this month', $e->reason);
        }
        self::assertSame(0, $this->fake->calls);
    }

    public function testZeroLimitMeansNoAi(): void
    {
        $this->school->getPlan()->setAiDraftsPerTeacherMonthly(0);
        $this->em->flush();

        $this->expectException(AiDraftFailed::class);
        $this->ai()->draft($this->lessonPlan(), $this->tess);
    }

    public function testLastMonthDoesNotCount(): void
    {
        foreach ([1, 2] as $_) {
            $this->em->persist(new AiDraft($this->tess, $this->school, 'm', 1, 1, new \DateTimeImmutable('2026-10-31 23:00')));
        }
        $this->em->flush();

        self::assertSame(2, $this->ai()->remaining($this->tess, $this->school));
    }

    public function testFailedDraftChangesNothingAndCountsNothing(): void
    {
        $plan = $this->lessonPlan();
        $this->fake->fail = 'the AI did not answer in time';

        try {
            $this->ai()->draft($plan, $this->tess);
            self::fail('Expected a failure.');
        } catch (AiDraftFailed $e) {
            self::assertSame('the AI did not answer in time', $e->reason);
        }

        self::assertSame('', $plan->getDays()[1]['objectives']);
        self::assertNull($plan->getDraftedAt());
        self::assertSame(0, $this->drafts());
    }

    public function testRedraftKeepsTeacherFields(): void
    {
        $plan = $this->lessonPlan();
        $this->ai()->draft($plan, $this->tess);
        $plan->setDays(array_map(static fn (array $d) => ['reflections' => 'edited'] + $d, $plan->getDays()));

        $this->ai()->draft($plan, $this->tess);

        self::assertSame('Multiplying monomials', $plan->getTopic());
        self::assertSame('Many slow readers', $plan->getClassNotes());
        self::assertStringContainsString('Divides monomials', $plan->getCompetencies());
        self::assertSame("reflections day 1 point 1\nreflections day 1 point 2", $plan->getDays()[1]['reflections']);
    }

    public function testAllHolidayWeekRefuses(): void
    {
        $plan = $this->lessonPlan();
        $plan->setDays([LessonPlan::emptyDay('2026-11-30', 'Bonifacio Day')]);

        try {
            $this->ai()->draft($plan, $this->tess);
            self::fail('Expected a refusal.');
        } catch (AiDraftFailed $e) {
            self::assertSame('this week has no school days to draft', $e->reason);
        }
        self::assertSame(0, $this->fake->calls);
    }

    public function testFilipinoSubjects(): void
    {
        $this->ai()->draft($this->lessonPlan('Araling Panlipunan'), $this->tess);

        self::assertTrue($this->fake->lastRequest->filipino);
    }
}
