<?php

namespace App\Tests\Controller;

use App\Entity\ClassPeriod;
use App\Entity\GradeLevel;
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

class TeacherLoadControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $maria;
    private User $santos;
    private Section $mabini;

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
        $this->mabini = (new Section($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setName('Mabini');
        $this->em->persist($this->mabini);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        // later test classes delete users without clearing school data first
        $this->clearSchoolData();
        parent::tearDown();
    }

    /** Two subjects of five meetings, all ten slots plotted for Ana Santos. */
    private function fillWeek(): void
    {
        $periods = [
            $this->classPeriod($this->school, $this->sy, '07:30', '08:30'),
            $this->classPeriod($this->school, $this->sy, '08:30', '09:30'),
        ];
        foreach ([['MATH8', 'Mathematics', 0], ['SCI8', 'Science', 1]] as [$code, $name, $i]) {
            $subject = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode($code)->setName($name)
                ->setPosition(1)->setMeetingsPerWeek(5)->setSemester(null);
            $this->em->persist($subject);
            $load = new TeachingLoad($this->mabini, $subject, $this->santos);
            $this->em->persist($load);
            foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $day) {
                $this->em->persist(new ScheduleEntry($load, $day, $periods[$i]));
            }
        }
        $this->em->flush();
    }

    public function testPrincipalSeesTeacherLoadAndTotal(): void
    {
        $this->fillWeek();
        $this->client->loginUser($this->maria);

        $crawler = $this->client->request('GET', '/schools/'.$this->school->getId().'/teachers/'.$this->santos->getId().'/load');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Ana Santos');
        self::assertSelectorTextContains('body', 'Total: 10 periods per week');
        self::assertStringContainsString('Mathematics', $crawler->filter('.timetable-grid')->text());
        self::assertStringContainsString('Grade 8 – Mabini', $crawler->filter('.timetable-grid')->text());
    }

    public function testTeacherOfOtherSchoolIs404(): void
    {
        $other = $this->school('999999', 'Other NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $outsider = $this->createUser('outsider', User::ROLE_TEACHER, 'Out Sider');
        $this->assign($other, $this->sy, $outsider, User::ROLE_TEACHER);
        $this->client->loginUser($this->maria);

        $this->client->request('GET', '/schools/'.$this->school->getId().'/teachers/'.$outsider->getId().'/load');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTeacherSeesOwnLoad(): void
    {
        $this->fillWeek();
        $this->client->loginUser($this->santos);

        $this->client->request('GET', '/teacher/load');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Total: 10 periods per week');
        self::assertSelectorTextContains('body', 'My Teaching Load');
    }

    public function testTeacherWithoutLoadSeesEmptyState(): void
    {
        $this->client->loginUser($this->santos);

        $this->client->request('GET', '/teacher/load');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'No teaching load yet.');
    }

    public function testTeacherHasNoEditControls(): void
    {
        $this->fillWeek();
        $this->client->loginUser($this->santos);

        $crawler = $this->client->request('GET', '/teacher/load');

        self::assertCount(0, $crawler->filter('[data-controller="timetable"]'));
    }

    public function testPrincipalCannotUseTeacherRoute(): void
    {
        $this->client->loginUser($this->maria);
        $this->client->request('GET', '/teacher/load');
        self::assertResponseStatusCodeSame(403);
    }
}
