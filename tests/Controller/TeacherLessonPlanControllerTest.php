<?php

namespace App\Tests\Controller;

use App\Entity\GradeLevel;
use App\Entity\LessonPlan;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\ScheduleEntry;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Entity\Weekday;
use App\Tests\Controller\Admin\AdminTestCase;
use App\Tests\LessonPlan\FakeLessonPlanDrafter;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class TeacherLessonPlanControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $tess;
    private User $ana;
    private TeachingLoad $load;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-11-20 10:00:00');
        $this->client->disableReboot();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->limitedPlan('With AI', 1000)->setAiDraftsPerTeacherMonthly(3);
        $this->em->flush();
        $this->school = $this->school('303196', 'Pagsabungan NHS', plan: 'With AI');
        $this->assign($this->school, $this->sy, $this->createUser('rosa', User::ROLE_PRINCIPAL, 'Rosa Cruz'));
        $this->tess = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->tess, User::ROLE_TEACHER);
        $this->ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Lim');
        $this->assign($this->school, $this->sy, $this->ana, User::ROLE_TEACHER);
        $this->load = $this->newLoad($this->tess, 'ARTISTIC', [Weekday::Monday, Weekday::Wednesday, Weekday::Friday]);
        $this->client->loginUser($this->tess);
    }

    private function fake(): FakeLessonPlanDrafter
    {
        return static::getContainer()->get(FakeLessonPlanDrafter::class);
    }

    /** @param list<Weekday> $days */
    private function newLoad(User $teacher, string $sectionName, array $days): TeachingLoad
    {
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, $sectionName);
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8-'.$sectionName)->setName('Mathematics')->setPosition(1);
        $this->em->persist($subject);
        $load = new TeachingLoad($section, $subject, $teacher);
        $this->em->persist($load);
        $period = $this->classPeriod($this->school, $this->sy, '07:30', '08:30');
        foreach ($days as $day) {
            $this->em->persist(new ScheduleEntry($load, $day, $period));
        }
        $this->em->flush();

        return $load;
    }

    private function planFor(TeachingLoad $load, string $monday = '2026-11-23'): LessonPlan
    {
        $plan = (new LessonPlan($load, new \DateTimeImmutable($monday), new \DateTimeImmutable()))
            ->setTopic('Multiplying monomials')->setCompetencies('Multiplies monomials')
            ->setDays([LessonPlan::emptyDay('2026-11-23'), LessonPlan::emptyDay('2026-11-25')]);
        $this->em->persist($plan);
        $this->em->flush();

        return $plan;
    }

    private function reload(LessonPlan $plan): LessonPlan
    {
        $this->em->clear();

        return $this->em->getRepository(LessonPlan::class)->find($plan->getId());
    }

    public function testListShowsOwnPlansOnly(): void
    {
        $this->planFor($this->load);
        $other = $this->planFor($this->newLoad($this->ana, 'SUPERB', [Weekday::Monday]));
        $other->setTopic('Ana topic');
        $this->em->flush();

        $this->client->request('GET', '/teacher/lesson-plans');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Multiplying monomials');
        self::assertSelectorTextNotContains('main', 'Ana topic');
        self::assertSelectorTextContains('.sidebar__link.is-active', 'My Lesson Plans');
    }

    public function testNewPlanUsesTimetableDays(): void
    {
        $this->client->request('GET', '/teacher/lesson-plans/new');
        $this->client->submitForm('Start plan', [
            'lesson_plan_start_form[load]' => (string) $this->load->getId(),
            'lesson_plan_start_form[week]' => '2026-11-26',
        ]);

        self::assertResponseRedirects();
        $plan = $this->em->getRepository(LessonPlan::class)->findOneBy([]);
        self::assertSame('2026-11-23', $plan->getWeekStart()->format('Y-m-d'));
        self::assertSame(['2026-11-23', '2026-11-25', '2026-11-27'], array_column($plan->getDays(), 'date'));
        self::assertResponseRedirects('/teacher/lesson-plans/'.$plan->getId());
    }

    public function testSameWeekOpensExisting(): void
    {
        $plan = $this->planFor($this->load);

        $this->client->request('GET', '/teacher/lesson-plans/new');
        $this->client->submitForm('Start plan', [
            'lesson_plan_start_form[load]' => (string) $this->load->getId(),
            'lesson_plan_start_form[week]' => '2026-11-27',
        ]);

        self::assertResponseRedirects('/teacher/lesson-plans/'.$plan->getId());
        self::assertCount(1, $this->em->getRepository(LessonPlan::class)->findAll());
    }

    public function testCannotStartAPlanForAnotherTeachersLoad(): void
    {
        $anas = $this->newLoad($this->ana, 'SUPERB', [Weekday::Monday]);
        $crawler = $this->client->request('GET', '/teacher/lesson-plans/new');
        $values = $crawler->selectButton('Start plan')->form()->getPhpValues();
        $values['lesson_plan_start_form']['load'] = (string) $anas->getId();
        $values['lesson_plan_start_form']['week'] = '2026-11-23';

        $this->client->request('POST', '/teacher/lesson-plans/new', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->em->getRepository(LessonPlan::class)->findAll());
    }

    public function testSaveEdits(): void
    {
        $plan = $this->planFor($this->load);

        $this->client->request('GET', '/teacher/lesson-plans/'.$plan->getId());
        self::assertSelectorTextContains('main', '3 of 3 AI drafts left this month');
        $this->client->submitForm('Save plan', [
            'lesson_plan_form[topic]' => 'Dividing monomials',
            'lesson_plan_form[days][1][flow]' => "Group work\nBoard work",
        ]);

        self::assertResponseRedirects('/teacher/lesson-plans/'.$plan->getId());
        $plan = $this->reload($plan);
        self::assertSame('Dividing monomials', $plan->getTopic());
        self::assertSame("Group work\nBoard work", $plan->getDays()[1]['flow']);
        self::assertSame('2026-11-25', $plan->getDays()[1]['date']);
    }

    public function testDraftFillsCells(): void
    {
        $plan = $this->planFor($this->load);

        $this->client->request('GET', '/teacher/lesson-plans/'.$plan->getId());
        $this->client->submitForm('Draft with AI', ['lesson_plan_form[topic]' => 'Laws of exponents']);

        self::assertResponseRedirects('/teacher/lesson-plans/'.$plan->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'Draft ready. Check every cell before you print.');
        self::assertSelectorTextContains('main', '2 of 3 AI drafts left this month');
        $plan = $this->reload($plan);
        self::assertSame('Laws of exponents', $plan->getTopic());
        self::assertStringContainsString('objectives day 1 point 1', $plan->getDays()[0]['objectives']);
        self::assertSame('Laws of exponents', $this->fake()->lastRequest->topic);
        self::assertCount(1, $this->client->getCrawler()->filter('button:contains("Redraft with AI")'));
    }

    public function testDraftFailureKeepsTypedTopic(): void
    {
        $plan = $this->planFor($this->load);
        $this->fake()->fail = 'the AI did not answer in time';

        $this->client->request('GET', '/teacher/lesson-plans/'.$plan->getId());
        $this->client->submitForm('Draft with AI', ['lesson_plan_form[topic]' => 'Laws of exponents']);

        self::assertResponseRedirects('/teacher/lesson-plans/'.$plan->getId());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', "The AI draft didn't work this time (the AI did not answer in time).");
        self::assertSame('Laws of exponents', $this->reload($plan)->getTopic());
        self::assertSelectorTextContains('main', '3 of 3 AI drafts left this month');
    }

    public function testNoDraftsLeftDisablesTheButton(): void
    {
        $this->school->getPlan()->setAiDraftsPerTeacherMonthly(0);
        $this->em->flush();
        $plan = $this->planFor($this->load);

        $crawler = $this->client->request('GET', '/teacher/lesson-plans/'.$plan->getId());

        self::assertCount(1, $crawler->filter('button[disabled]:contains("Draft with AI")'));
    }

    public function testOtherTeachersPlanIsForbidden(): void
    {
        $plan = $this->planFor($this->newLoad($this->ana, 'SUPERB', [Weekday::Monday]));

        $this->client->request('GET', '/teacher/lesson-plans/'.$plan->getId());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/teacher/lesson-plans/'.$plan->getId().'/delete', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertNotNull($this->reload($plan));
    }

    public function testDelete(): void
    {
        $plan = $this->planFor($this->load);
        $this->client->request('GET', '/teacher/lesson-plans/'.$plan->getId());

        $this->client->request('POST', '/teacher/lesson-plans/'.$plan->getId().'/delete', ['_token' => $this->csrfToken('lesson-plan-delete-'.$plan->getId())]);

        self::assertResponseRedirects('/teacher/lesson-plans');
        $this->em->clear();
        self::assertNull($this->em->getRepository(LessonPlan::class)->find($plan->getId()));
    }

    public function testMenuItem(): void
    {
        $crawler = $this->client->request('GET', '/teacher');

        self::assertContains('My Lesson Plans', $crawler->filter('.sidebar__link')->each(static fn ($a) => $a->text()));
    }
}
