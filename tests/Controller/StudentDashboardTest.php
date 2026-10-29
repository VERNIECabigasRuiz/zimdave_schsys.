<?php

namespace App\Tests\Controller;

use App\Entity\GradeLevel;
use App\Entity\Learner;
use App\Entity\ScheduleEntry;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Entity\Weekday;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class StudentDashboardTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $maria;
    private User $santos;
    private Learner $learner;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-16');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school, $this->sy, $this->maria);
        $this->santos = $this->createUser('santos', User::ROLE_TEACHER, 'Ana Santos');
        $this->assign($this->school, $this->sy, $this->santos, User::ROLE_TEACHER);
        $this->learner = $this->learner('123456789012', 'Cruz', 'Juan');
        $this->learner->getUser()->setMustChangePassword(false);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function plot(Section $section, string $name, ?int $semester): void
    {
        $period = $this->classPeriod($this->school, $this->sy, '07:30', '08:30');
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel($section->getGradeLevel())->setCode(strtoupper($name))->setName($name)
            ->setPosition(1)->setMeetingsPerWeek(5)->setSemester($semester);
        $this->em->persist($subject);
        $load = new TeachingLoad($section, $subject, $this->santos);
        $this->em->persist($load);
        $this->em->persist(new ScheduleEntry($load, Weekday::Monday, $period));
        $this->em->flush();
    }

    public function testHomeRedirectsStudent(): void
    {
        $this->client->loginUser($this->learner->getUser());
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/student');
    }

    public function testShowsSchoolSectionAdviserYear(): void
    {
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Mabini');
        $section->setAdviser($this->santos);
        $this->enroll($this->learner, $this->sy, $section, $this->maria);
        $this->client->loginUser($this->learner->getUser());

        $this->client->request('GET', '/student');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Pagsabungan NHS');
        self::assertSelectorTextContains('body', 'Grade 8 – Mabini');
        self::assertSelectorTextContains('body', 'Ana Santos');
        self::assertSelectorTextContains('body', $this->sy->getName());
    }

    public function testShowsOwnSf1InfoReadOnly(): void
    {
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Mabini');
        $this->enroll($this->learner, $this->sy, $section, $this->maria);
        $this->learner->setReligion('Christianity')->setAddressCity('Mandaue City');
        $this->em->flush();
        $this->client->loginUser($this->learner->getUser());

        $crawler = $this->client->request('GET', '/student');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Christianity');
        self::assertSelectorTextContains('body', 'Mandaue City');
        self::assertSelectorTextContains('body', 'Face to Face');
        self::assertCount(0, $crawler->filter('form[name="learner_form"]'));
    }

    public function testNoAdviserYet(): void
    {
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Mabini');
        $this->enroll($this->learner, $this->sy, $section, $this->maria);
        $this->client->loginUser($this->learner->getUser());

        $this->client->request('GET', '/student');

        self::assertSelectorTextContains('body', 'No adviser yet');
    }

    public function testShowsTimetable(): void
    {
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Mabini');
        $this->enroll($this->learner, $this->sy, $section, $this->maria);
        $this->plot($section, 'Mathematics', null);
        $this->client->loginUser($this->learner->getUser());

        $crawler = $this->client->request('GET', '/student');

        $grid = $crawler->filter('.timetable-grid')->text();
        self::assertStringContainsString('Mathematics', $grid);
        self::assertStringContainsString('Ana Santos', $grid);
        self::assertSelectorNotExists('a:contains("1st sem")');
    }

    public function testShsSemesterSwitch(): void
    {
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade11, 'STEM-A');
        $this->enroll($this->learner, $this->sy, $section, $this->maria);
        $this->plot($section, 'Physics', 1);
        $this->client->loginUser($this->learner->getUser());

        $crawler = $this->client->request('GET', '/student');
        self::assertStringContainsString('Physics', $crawler->filter('.timetable-grid')->text());
        self::assertSelectorExists('a:contains("2nd sem")');

        $crawler = $this->client->request('GET', '/student?term=2');
        self::assertStringNotContainsString('Physics', $crawler->filter('.timetable-grid')->text());

        $crawler = $this->client->request('GET', '/student?term=9');
        self::assertStringContainsString('Physics', $crawler->filter('.timetable-grid')->text());
    }

    public function testNotEnrolled(): void
    {
        $this->client->loginUser($this->learner->getUser());

        $this->client->request('GET', '/student');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'You are not enrolled this school year.');
    }

    public function testTeacherCannotOpen(): void
    {
        $this->client->loginUser($this->santos);

        $this->client->request('GET', '/student');

        self::assertResponseStatusCodeSame(403);
    }

    public function testFirstSignInForcesPasswordChange(): void
    {
        $user = $this->learner->getUser();
        $user->setMustChangePassword(true);
        $this->em->flush();
        $this->client->loginUser($user);

        $this->client->request('GET', '/student');

        self::assertResponseRedirects('/change-password');
    }
}
