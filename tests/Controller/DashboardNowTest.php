<?php

namespace App\Tests\Controller;

use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\User;
use App\Entity\Weekday;
use App\Service\TimetableManager;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class DashboardNowTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $maria;
    private User $santos;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-19 08:00:00 Asia/Manila');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Cruz');
        $this->assign($this->school, $this->sy, $this->maria);
        $this->santos = $this->createUser('santos', User::ROLE_TEACHER, 'Ana Santos');
        $this->assign($this->school, $this->sy, $this->santos, User::ROLE_TEACHER);
        $reyes = $this->createUser('reyes', User::ROLE_TEACHER, 'Jose Reyes');
        $this->assign($this->school, $this->sy, $reyes, User::ROLE_TEACHER);
        $p1 = $this->classPeriod($this->school, $this->sy, '07:30', '08:30');

        $section = (new Section($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setName('Rizal');
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setCode('MATH7')->setName('Mathematics')
            ->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($section);
        $this->em->persist($subject);
        $this->em->flush();
        $manager = static::getContainer()->get(TimetableManager::class);
        $manager->setTeacher($section, $subject, $this->santos);
        $manager->plot($section, $subject, Weekday::Monday, $p1);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    public function testPrincipalDashboardHasNowFrame(): void
    {
        $this->client->loginUser($this->maria);
        $crawler = $this->client->request('GET', '/principal');

        self::assertResponseIsSuccessful();
        $frame = $crawler->filter('turbo-frame#now');
        self::assertCount(1, $frame);
        self::assertSame('/principal/now', $frame->attr('src'));
        self::assertSame('refresh', $frame->attr('data-controller'));
    }

    public function testPrincipalNowFragment(): void
    {
        $this->client->loginUser($this->maria);
        $crawler = $this->client->request('GET', '/principal/now');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#now'));
        $text = $crawler->filter('turbo-frame#now')->text();
        self::assertStringContainsString('Classes right now', $text);
        self::assertStringContainsString('Ana Santos', $text);
        self::assertStringContainsString('Mathematics', $text);
        self::assertStringContainsString('Free this period', $text);
        self::assertStringContainsString('Jose Reyes', $text);
        self::assertStringNotContainsString('<html', (string) $this->client->getResponse()->getContent());
    }

    public function testPrincipalNowOnWeekend(): void
    {
        static::mockTime('2026-10-24 08:00:00 Asia/Manila');
        $this->client->loginUser($this->maria);
        $this->client->request('GET', '/principal/now');

        self::assertSelectorTextContains('turbo-frame#now', 'No classes today.');
    }

    public function testTeacherDashboardHasNowFrame(): void
    {
        $this->client->loginUser($this->santos);
        $crawler = $this->client->request('GET', '/teacher');

        self::assertResponseIsSuccessful();
        self::assertSame('/teacher/now', $crawler->filter('turbo-frame#now')->attr('src'));
    }

    public function testTeacherNowFragment(): void
    {
        $this->client->loginUser($this->santos);
        $this->client->request('GET', '/teacher/now');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#now', 'Now: Mathematics · Grade 7 – Rizal · until 08:30');
    }

    public function testNowFragmentsAreRoleProtected(): void
    {
        $this->client->loginUser($this->santos);
        $this->client->request('GET', '/principal/now');
        self::assertResponseStatusCodeSame(403);
    }
}
