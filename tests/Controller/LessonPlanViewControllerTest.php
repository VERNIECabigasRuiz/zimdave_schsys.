<?php

namespace App\Tests\Controller;

use App\Entity\GradeLevel;
use App\Entity\LessonPlan;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\ServiceRecordEntry;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Access\Domain\Model\User;
use App\LessonPlan\LessonPlanPdf;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class LessonPlanViewControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $rosa;
    private User $tess;
    private LessonPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-11-20 10:00:00');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->school->setLpCheckedByName('JULIE ANN I. NONOL')->setLpCheckedByPosition('Master Teacher I');
        $this->rosa = $this->createUser('rosa', User::ROLE_PRINCIPAL, 'Rosa Cruz');
        $this->assign($this->school, $this->sy, $this->rosa);
        $this->tess = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->tess, User::ROLE_TEACHER);

        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'ARTISTIC');
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')->setName('Mathematics')->setPosition(1);
        $this->em->persist($subject);
        $load = new TeachingLoad($section, $subject, $this->tess);
        $this->em->persist($load);
        $day = LessonPlan::emptyDay('2026-12-01');
        foreach (LessonPlan::DAY_FIELDS as $field) {
            $day[$field] = "$field first\n$field second";
        }
        $this->plan = (new LessonPlan($load, new \DateTimeImmutable('2026-11-30'), new \DateTimeImmutable()))
            ->setTopic('Multiplying monomials')
            ->setCompetencies('Multiplies simple monomials (M8AL-Ia-1)')
            ->setReferences("Mathematics 8 Learner's Module")
            ->setAiDeclaration('No AI was used; the teacher wrote this plan.')
            ->setDays([LessonPlan::emptyDay('2026-11-30', 'Bonifacio Day'), $day]);
        $this->em->persist($this->plan);
        $this->em->persist((new ServiceRecordEntry($this->tess, new \DateTimeImmutable('2020-01-01'), $this->rosa, new \DateTimeImmutable()))
            ->setDesignation('Teacher III')->setStatus('Regular/Permanent')->setAnnualSalary('300000')->setStation('Mandaue City')->setBranch('National'));
        $this->em->flush();
    }

    private function pdf(): LessonPlanPdf
    {
        return static::getContainer()->get(LessonPlanPdf::class);
    }

    public function testHtmlHasEveryBlockDayAndSignatory(): void
    {
        $html = $this->pdf()->html($this->plan);

        foreach ([
            'DAILY LESSON LOG-ILAW FORMAT', 'Multiplying monomials', 'Mathematics', 'TESS REYES', 'Grade 8 ARTISTIC', '1 session',
            "Mathematics 8 Learner&#039;s Module", 'No AI was used', 'M8AL-Ia-1',
            'Intentions.', 'Learning Experience.', 'Assessment.', 'Ways Forward.',
            'Day 1', 'Day 2', 'HOLIDAY', 'Bonifacio Day', 'December 1',
            'objectives first', 'learnerContext second', 'preLesson first', 'flow first', 'resources first', 'integration first',
            'formativeAssessment first', 'extendedLearning first', 'reflections second',
            'Prepared by:', 'Teacher III', 'Checked by:', 'JULIE ANN I. NONOL', 'Master Teacher I', 'Noted by:', 'ROSA CRUZ', 'School Principal',
        ] as $text) {
            self::assertStringContainsString($text, $html);
        }
    }

    public function testRenderAndFilename(): void
    {
        self::assertStringStartsWith('%PDF-', $this->pdf()->render($this->plan));
        self::assertSame('ILAW - Mathematics - ARTISTIC - 2026-11-30.pdf', LessonPlanPdf::filename($this->plan));
    }

    public function testTeacherDownloadsOwnPdf(): void
    {
        $this->client->loginUser($this->tess);

        $this->client->request('GET', '/lesson-plans/'.$this->plan->getId().'.pdf');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString('ILAW - Mathematics - ARTISTIC - 2026-11-30.pdf', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testPrincipalListsAndViewsSchoolPlans(): void
    {
        $this->client->loginUser($this->rosa);

        $crawler = $this->client->request('GET', '/principal/lesson-plans');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Multiplying monomials');
        self::assertSelectorTextContains('main', 'Tess Reyes');
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Lesson Plans');

        $this->client->click($crawler->selectLink('View')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'flow first');
        self::assertCount(0, $this->client->getCrawler()->filter('textarea'));

        $this->client->request('GET', '/lesson-plans/'.$this->plan->getId().'.pdf');
        self::assertResponseIsSuccessful();
    }

    public function testOtherSchoolsPrincipalIsDenied(): void
    {
        $other = $this->school('303197', 'Other NHS');
        $boss = $this->createUser('boss', User::ROLE_PRINCIPAL);
        $this->assign($other, $this->sy, $boss);
        $this->client->loginUser($boss);

        $this->client->request('GET', '/lesson-plans/'.$this->plan->getId());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/lesson-plans/'.$this->plan->getId().'.pdf');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/principal/lesson-plans');
        self::assertSelectorTextNotContains('main', 'Multiplying monomials');
    }

    public function testOtherTeacherIsDenied(): void
    {
        $ana = $this->createUser('ana', User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $ana, User::ROLE_TEACHER);
        $this->client->loginUser($ana);

        $this->client->request('GET', '/lesson-plans/'.$this->plan->getId().'.pdf');

        self::assertResponseStatusCodeSame(403);
    }
}
