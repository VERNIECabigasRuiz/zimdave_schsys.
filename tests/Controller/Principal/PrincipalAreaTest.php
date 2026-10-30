<?php

namespace App\Tests\Controller\Principal;

use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class PrincipalAreaTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-16');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->client->loginUser($this->maria);
    }

    private function makePrincipal(): void
    {
        $this->assign($this->school, $this->sy, $this->maria);
    }

    public function testPrincipalIsSentToPrincipalArea(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/principal');
    }

    public function testDashboardShowsSchoolAndUsage(): void
    {
        $this->makePrincipal();
        foreach (['ana', 'ben'] as $username) {
            $this->assign($this->school, $this->sy, $this->createUser($username, User::ROLE_TEACHER), User::ROLE_TEACHER);
        }

        $crawler = $this->client->request('GET', '/principal');

        self::assertResponseIsSuccessful();
        $head = $crawler->filter('.page-head')->text();
        self::assertStringContainsString('Pagsabungan NHS', $head);
        self::assertStringContainsString('S.Y. 2026-2027', $head);
        self::assertStringContainsString('Basic plan', $head);
        self::assertStringContainsString('Apr 30, 2027', $head);
        $stats = $crawler->filter('.stat')->each(static fn ($node) => $node->filter('small')->text().'='.$node->filter('strong')->text());
        self::assertSame(['Teachers=2 / 15', 'Students=0 / 1,000', 'Attendance today · 0 absent=0 / 0'], $stats);
    }

    public function testDashboardCountsEnrolledLearners(): void
    {
        $this->makePrincipal();
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Rizal');
        $this->enroll($this->learner('100000000001', 'Cruz', 'Juan'), $this->sy, $section, $this->maria);

        $crawler = $this->client->request('GET', '/principal');

        $stats = $crawler->filter('.stat')->each(static fn ($node) => $node->filter('small')->text().'='.$node->filter('strong')->text());
        self::assertContains('Students=1 / 1,000', $stats);
    }

    public function testExpiredPlanWarning(): void
    {
        $this->em->getRepository(School::class)->find($this->school->getId())->setPlanValidUntil(new \DateTimeImmutable('2026-10-15'));
        $this->em->flush();
        $this->makePrincipal();

        $this->client->request('GET', '/principal');

        self::assertSelectorTextContains('.alert--error', 'The plan of Pagsabungan NHS expired on Oct 15, 2026.');
    }

    public function testNotAssignedPage(): void
    {
        foreach (['/principal', '/principal/teachers'] as $url) {
            $this->client->request('GET', $url);

            self::assertResponseIsSuccessful();
            self::assertSelectorTextContains('main', 'You are not assigned to a school for S.Y. 2026-2027. Contact the administrator.');
        }
    }

    public function testNoCurrentSchoolYear(): void
    {
        $this->em->getRepository(SchoolYear::class)->find($this->sy->getId())->setCurrent(false);
        $this->em->flush();

        $this->client->request('GET', '/principal');

        self::assertSelectorTextContains('main', 'No current school year.');
    }

    public function testTeachersLinkGoesToOwnSchool(): void
    {
        $this->makePrincipal();

        $this->client->request('GET', '/principal/teachers');

        self::assertResponseRedirects('/schools/'.$this->school->getId().'/teachers');
    }

    public function testSharedPageUsesPrincipalLayout(): void
    {
        $this->makePrincipal();

        $crawler = $this->client->request('GET', '/schools/'.$this->school->getId().'/teachers');

        self::assertSame(['Dashboard', 'Teachers', 'Sections', 'Learners', 'Attendance', 'Nutrition', 'Subjects', 'Class Periods', 'School Profile', 'My Profile'], $crawler->filter('.sidebar__link')->each(static fn ($a) => $a->text()));
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Teachers');
        self::assertSelectorNotExists('.topbar__search');
    }

    public function testTeacherCannotOpenPrincipalArea(): void
    {
        $this->client->loginUser($this->createUser('ana', User::ROLE_TEACHER));

        $this->client->request('GET', '/principal');

        self::assertResponseStatusCodeSame(403);
    }

    public function testPrincipalCannotOpenAdmin(): void
    {
        $this->makePrincipal();

        $this->client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }
}
