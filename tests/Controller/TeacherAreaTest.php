<?php

namespace App\Tests\Controller;

use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class TeacherAreaTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-16');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->client->loginUser($this->ana);
    }

    public function testTeacherIsSentToTeacherArea(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/teacher');
    }

    public function testDashboardShowsSchoolAndSchoolYear(): void
    {
        $this->assign($this->school, $this->sy, $this->ana, User::ROLE_TEACHER);

        $crawler = $this->client->request('GET', '/teacher');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Pagsabungan NHS');
        self::assertSelectorTextContains('main', 'S.Y. 2026-2027');
        self::assertSame(['Dashboard', 'My Teaching Load', 'My Profile'], $crawler->filter('.sidebar__link')->each(static fn ($a) => $a->text()));
        self::assertSelectorTextContains('.sidebar__brand', 'Teacher');
        self::assertSelectorExists('turbo-frame#now');
    }

    public function testDashboardWithoutAssignment(): void
    {
        $this->client->request('GET', '/teacher');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'You are not assigned to a school for S.Y. 2026-2027.');
    }

    public function testTeacherUsesEveryProfileTabWithTeacherNav(): void
    {
        foreach (['/profile', '/profile/personal', '/profile/family', '/profile/education', '/profile/eligibility', '/profile/work', '/profile/training', '/profile/voluntary', '/profile/other', '/profile/questions', '/profile/references'] as $url) {
            $crawler = $this->client->request('GET', $url);

            self::assertResponseIsSuccessful($url);
            self::assertSame(['Dashboard', 'My Teaching Load', 'My Profile'], $crawler->filter('.sidebar__link')->each(static fn ($a) => $a->text()), $url);
        }
        $this->client->request('GET', '/profile/pds.pdf');
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
    }

    public function testTeacherCannotOpenPrincipalOrAdminAreas(): void
    {
        foreach (['/principal', '/principal/school', '/admin'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $url);
        }
    }

    public function testAdminAndStudentCannotOpenProfile(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', '/profile');
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->createUser('stud', User::ROLE_STUDENT));
        $this->client->request('GET', '/profile');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDashboardShowsAdvisory(): void
    {
        $this->assign($this->school, $this->sy, $this->ana, User::ROLE_TEACHER);
        $this->client->request('GET', '/teacher');
        self::assertSelectorTextContains('main', 'No advisory class this S.Y.');
        self::assertSelectorNotExists('a:contains("Create my section")');

        $section = (new \App\Entity\Section($this->school, $this->sy))->setGradeLevel(\App\Entity\GradeLevel::Grade7)->setName('Sampaguita')->setAdviser($this->ana);
        $this->em->persist($section);
        $this->em->flush();

        $this->client->request('GET', '/teacher');
        self::assertSelectorTextContains('main', 'Adviser of Grade 7 – Sampaguita');
    }

    public function testBasicPlanTeacherCannotCreateOwnSection(): void
    {
        $this->assign($this->school, $this->sy, $this->ana, User::ROLE_TEACHER);

        $this->client->request('GET', '/teacher/section');

        self::assertResponseStatusCodeSame(403);
    }

    public function testIndividualPlanTeacherCreatesOneOwnSection(): void
    {
        $own = $this->school('303999', 'Ana Home Class', plan: 'Individual', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->assign($own, $this->sy, $this->ana, User::ROLE_TEACHER);

        $this->client->request('GET', '/teacher');
        $this->client->clickLink('Create my section');
        $this->client->submitForm('Create my section', [
            'section_form[gradeLevel]' => '8',
            'section_form[name]' => 'Narra',
        ]);

        self::assertResponseRedirects('/teacher');
        $this->em->clear();
        $sections = $this->em->getRepository(\App\Entity\Section::class)->findAll();
        self::assertCount(1, $sections);
        self::assertSame(['Grade 8 – Narra', 'ana', $own->getId()], [$sections[0]->getLabel(), $sections[0]->getAdviser()->getUsername(), $sections[0]->getSchool()->getId()]);
        self::assertSelectorNotExists('input[name="section_form[adviser]"]');

        $this->client->request('GET', '/teacher/section');
        self::assertResponseRedirects('/teacher');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'You already advise Grade 8 – Narra.');
    }

    public function testIndividualPlanTeacherHasNoTimetableExtras(): void
    {
        $own = $this->school('303999', 'Ana Home Class', plan: 'Individual', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->assign($own, $this->sy, $this->ana, User::ROLE_TEACHER);

        foreach (['/teacher', '/profile'] as $url) {
            $crawler = $this->client->request('GET', $url);
            self::assertResponseIsSuccessful();
            self::assertSame(['Dashboard', 'My Profile'], $crawler->filter('.sidebar__link')->each(static fn ($a) => $a->text()), $url);
        }
        $this->client->request('GET', '/teacher');
        self::assertSelectorNotExists('turbo-frame#now');
    }
}
