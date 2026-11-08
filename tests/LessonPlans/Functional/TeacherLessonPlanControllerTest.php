<?php

declare(strict_types=1);

namespace App\Tests\LessonPlans\Functional;

use App\Schools\Domain\Model\GradeLevel;
use App\LessonPlans\Domain\Model\LessonPlan;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\ScheduleEntry;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Weekday;
use App\Tests\Controller\Admin\AdminTestCase;
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
        $this->limitedPlan('Standard', 1000);
        $this->em->flush();
        $this->school = $this->school('303196', 'Pagsabungan NHS', plan: 'Standard');
        $this->assign($this->school, $this->sy, $this->createUser('rosa', User::ROLE_PRINCIPAL, 'Rosa Cruz'));
        $this->tess = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->tess, User::ROLE_TEACHER);
        $this->ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Lim');
        $this->assign($this->school, $this->sy, $this->ana, User::ROLE_TEACHER);
        $this->load = $this->newLoad($this->tess, 'ARTISTIC', [Weekday::Monday, Weekday::Wednesday, Weekday::Friday]);
        $this->client->loginUser($this->tess);
    }

    /** @param list<Weekday> $days */
    private function newLoad(User $teacher, string $sectionName, array $days): TeachingLoad
    {
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, $sectionName);
        $subject = (new Subject($this->school, $this->sy))
            ->setGradeLevel(GradeLevel::Grade8)
            ->setCode('MATH8-' . $sectionName)
            ->setName('Mathematics')
            ->setPosition(1);
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
        $found = $this->em->getRepository(LessonPlan::class)->find($plan->getId());
        self::assertNotNull($found);

        return $found;
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
        self::assertNotNull($plan);
        self::assertSame('2026-11-23', $plan->getWeekStart()->format('Y-m-d'));
        self::assertSame(['2026-11-23', '2026-11-25', '2026-11-27'], array_column($plan->getDays(), 'date'));
        self::assertResponseRedirects('/teacher/lesson-plans/' . $plan->getId());
    }

    public function testSameWeekOpensExisting(): void
    {
        $plan = $this->planFor($this->load);

        $this->client->request('GET', '/teacher/lesson-plans/new');
        $this->client->submitForm('Start plan', [
            'lesson_plan_start_form[load]' => (string) $this->load->getId(),
            'lesson_plan_start_form[week]' => '2026-11-27',
        ]);

        self::assertResponseRedirects('/teacher/lesson-plans/' . $plan->getId());
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

    public function testATopicOverTwoHundredCharactersIsRefused(): void
    {
        // Initialize
        $plan = $this->planFor($this->load);
        $this->client->request('GET', '/teacher/lesson-plans/' . $plan->getId());

        // Execute
        $this->client->submitForm('Save plan', ['lesson_plan_form[topic]' => str_repeat('a', 201)]);

        // Assert
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'This value is too long.');
        self::assertCount(2, $this->client->getCrawler()->filter('main li:contains("This value is too long.")'));
        self::assertSame('Multiplying monomials', $this->reload($plan)->getTopic());
    }

    public function testARefusedSaveHeadsThePageWithTheTopicJustTyped(): void
    {
        // Initialize
        $plan = $this->planFor($this->load);
        $this->client->request('GET', '/teacher/lesson-plans/' . $plan->getId());

        // Execute
        $this->client->submitForm('Save plan', ['lesson_plan_form[topic]' => '']);

        // Assert
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('h1', 'New lesson plan');
        self::assertPageTitleContains('Lesson plan · New · ZIM School');
    }

    public function testATeacherWhoIsNoLongerATeacherIsForbidden(): void
    {
        // Initialize
        $plan = $this->planFor($this->load);
        $tess = $this->em->getRepository(User::class)->find($this->tess->getId());
        self::assertNotNull($tess);
        $tess->setRole(User::ROLE_PRINCIPAL);
        $this->em->flush();
        $this->client->loginUser($tess);
        $requests = [
            ['GET', '/teacher/lesson-plans'],
            ['GET', '/teacher/lesson-plans/new'],
            ['GET', '/teacher/lesson-plans/' . $plan->getId()],
            ['POST', '/teacher/lesson-plans/' . $plan->getId() . '/delete'],
        ];

        // Execute
        $statuses = [];
        foreach ($requests as [$method, $url]) {
            $this->client->request($method, $url);
            $statuses[] = $this->client->getResponse()->getStatusCode();
        }

        // Assert
        self::assertSame([403, 403, 403, 403], $statuses);
    }

    public function testABadTokenKeepsThePlan(): void
    {
        // Initialize
        $plan = $this->planFor($this->load);

        // Execute
        $this->client->request('POST', '/teacher/lesson-plans/' . $plan->getId() . '/delete', ['_token' => 'x']);

        // Assert
        self::assertResponseRedirects('/login');
        self::assertSame($plan->getId(), $this->reload($plan)->getId());
    }

    public function testAnUnknownPlanIsNotFound(): void
    {
        // Initialize
        $missing = (int) $this->planFor($this->load)->getId() + 1000;

        // Execute
        $this->client->request('GET', '/teacher/lesson-plans/' . $missing);

        // Assert
        self::assertResponseStatusCodeSame(404);
    }

    public function testAHolidayHasNoCellsAndStaysAHoliday(): void
    {
        // Initialize
        $plan = $this->planFor($this->load)->setDays([
            LessonPlan::emptyDay('2026-11-23'),
            LessonPlan::emptyDay('2026-11-25', 'Bonifacio Day'),
        ]);
        $this->em->flush();
        $crawler = $this->client->request('GET', '/teacher/lesson-plans/' . $plan->getId());
        $holidayCells = $crawler->filter('textarea[name^="lesson_plan_form[days][1]"]')->count();
        $schoolDayCells = $crawler->filter('textarea[name^="lesson_plan_form[days][0]"]')->count();

        // Execute
        $this->client->submitForm('Save plan', ['lesson_plan_form[days][0][flow]' => 'Board work']);

        // Assert
        self::assertSame([0, \count(LessonPlan::DAY_FIELDS)], [$holidayCells, $schoolDayCells]);
        self::assertResponseRedirects('/teacher/lesson-plans/' . $plan->getId());
        $days = $this->reload($plan)->getDays();
        self::assertSame(['Board work', 'Bonifacio Day'], [$days[0]['flow'], $days[1]['holiday']]);
    }

    public function testSaveEdits(): void
    {
        $plan = $this->planFor($this->load);

        $this->client->request('GET', '/teacher/lesson-plans/' . $plan->getId());
        $this->client->submitForm('Save plan', [
            'lesson_plan_form[topic]' => 'Dividing monomials',
            'lesson_plan_form[days][1][flow]' => "Group work\nBoard work",
        ]);

        self::assertResponseRedirects('/teacher/lesson-plans/' . $plan->getId());
        $plan = $this->reload($plan);
        self::assertSame('Dividing monomials', $plan->getTopic());
        self::assertSame("Group work\nBoard work", $plan->getDays()[1]['flow']);
        self::assertSame('2026-11-25', $plan->getDays()[1]['date']);
    }

    public function testOtherTeachersPlanIsForbidden(): void
    {
        $plan = $this->planFor($this->newLoad($this->ana, 'SUPERB', [Weekday::Monday]));

        $this->client->request('GET', '/teacher/lesson-plans/' . $plan->getId());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/teacher/lesson-plans/' . $plan->getId() . '/delete', ['_token' => 'x']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame($plan->getId(), $this->reload($plan)->getId());
    }

    public function testDelete(): void
    {
        $plan = $this->planFor($this->load);
        $this->client->request('GET', '/teacher/lesson-plans/' . $plan->getId());

        $this->client->request(
            'POST',
            '/teacher/lesson-plans/' . $plan->getId() . '/delete',
            ['_token' => $this->csrfToken('lesson-plan-delete-' . $plan->getId())],
        );

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
