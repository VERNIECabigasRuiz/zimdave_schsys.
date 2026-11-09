<?php

declare(strict_types=1);

namespace App\Tests\Dashboards\Functional;

use App\Grading\Domain\Model\GradeChangeRequest;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Subject;
use App\Access\Domain\Model\User;
use App\Tests\Support\AdminTestCase;
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
        $this->school = $this->school(
            '303456',
            'Pagsabungan NHS',
            planValidUntil: new \DateTimeImmutable('2027-04-30'),
        );
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
            $this->assign(
                $this->school,
                $this->sy,
                $this->createUser($username, User::ROLE_TEACHER),
                User::ROLE_TEACHER,
            );
        }

        $crawler = $this->client->request('GET', '/principal');

        self::assertResponseIsSuccessful();
        $head = $crawler->filter('.page-head')->text();
        self::assertStringContainsString('Pagsabungan NHS', $head);
        self::assertStringContainsString('S.Y. 2026-2027', $head);
        self::assertStringContainsString('Basic plan', $head);
        self::assertStringContainsString('Apr 30, 2027', $head);
        $stats = $crawler->filter('.stat')->each(
            static fn ($node) => $node->filter('small')->text() . '=' . $node->filter('strong')->text(),
        );
        self::assertSame(['Teachers=2 / 15', 'Students=0 / 1,000', 'Students present today · 0 absent=0 / 0'], $stats);
    }

    public function testDashboardCountsEnrolledLearners(): void
    {
        $this->makePrincipal();
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Rizal');
        $this->enroll($this->learner('100000000001', 'Cruz', 'Juan'), $this->sy, $section, $this->maria);

        $crawler = $this->client->request('GET', '/principal');

        $stats = $crawler->filter('.stat')->each(
            static fn ($node) => $node->filter('small')->text() . '=' . $node->filter('strong')->text(),
        );
        self::assertContains('Students=1 / 1,000', $stats);
    }

    public function testExpiredPlanWarning(): void
    {
        $school = $this->em->getRepository(School::class)->find($this->school->getId());
        self::assertNotNull($school);
        $school->setPlanValidUntil(new \DateTimeImmutable('2026-10-15'));
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
            self::assertSelectorTextContains(
                'main',
                'You are not assigned to a school for S.Y. 2026-2027. Contact the administrator.',
            );
        }
    }

    public function testNotAssignedPrincipalHasNoServiceRecord(): void
    {
        $this->client->request('GET', '/principal');
        self::assertSelectorNotExists('.sidebar a[href="/principal/service-record"]');

        $this->client->request('GET', '/principal/service-record');
        self::assertResponseStatusCodeSame(403);
    }

    public function testNoCurrentSchoolYear(): void
    {
        $schoolYear = $this->em->getRepository(SchoolYear::class)->find($this->sy->getId());
        self::assertNotNull($schoolYear);
        $schoolYear->setCurrent(false);
        $this->em->flush();

        $this->client->request('GET', '/principal');

        self::assertSelectorTextContains('main', 'No current school year.');
    }

    public function testTeachersLinkGoesToOwnSchool(): void
    {
        $this->makePrincipal();

        $this->client->request('GET', '/principal/teachers');

        self::assertResponseRedirects('/schools/' . $this->school->getId() . '/teachers');
    }

    public function testSharedPageUsesPrincipalLayout(): void
    {
        $this->makePrincipal();

        $crawler = $this->client->request('GET', '/schools/' . $this->school->getId() . '/teachers');

        self::assertSame(
            [
                'Dashboard',
                'Teachers',
                'Sections',
                'Learners',
                'Attendance',
                'Nutrition',
                'Grades',
                'Lesson Plans',
                'Subjects',
                'Class Periods',
                'School Profile',
                'My Profile',
                'My ID',
                'My Service Record',
            ],
            $crawler->filter('.sidebar__link')->each(static fn ($a) => $a->text()),
        );
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

    public function testGradeChangeCard(): void
    {
        $this->makePrincipal();
        $crawler = $this->client->request('GET', '/principal');
        self::assertCount(0, $crawler->filter('.stat:contains("Grade change requests waiting")'));

        $this->sy->rebuildGradingPeriods();
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Honest');
        $math = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MATH8')
            ->setName('Mathematics')->setPosition(1)->setMeetingsPerWeek(5);
        $this->em->persist($math);
        $firstPeriod = $this->sy->getGradingPeriod(1);
        self::assertNotNull($firstPeriod);
        $this->em->persist(
            new GradeChangeRequest(
                $section,
                $math,
                $firstPeriod,
                $this->createUser('tess', User::ROLE_TEACHER),
                'Encoding error on a quiz',
                new \DateTimeImmutable('2026-10-16'),
            ),
        );
        $this->em->flush();

        $crawler = $this->client->request('GET', '/principal');

        $card = $crawler->filter('.stat:contains("Grade change requests waiting")');
        self::assertCount(1, $card);
        self::assertSame('Grade change requests waiting: 1', $card->filter('strong')->text());
        self::assertSame('Approve or decline', $card->filter('small')->text());
        self::assertSame('/schools/' . $this->school->getId() . '/grade-changes', $card->attr('href'));
    }

    public function testEveryShortcutGoesToTheOwnSchoolPage(): void
    {
        // Initialize
        $this->makePrincipal();
        $id = $this->school->getId();
        $shortcuts = [
            '/principal/teachers' => "/schools/$id/teachers",
            '/principal/sections' => "/schools/$id/sections",
            '/principal/learners' => "/schools/$id/learners",
            '/principal/attendance' => "/schools/$id/attendance",
            '/principal/nutrition' => "/schools/$id/nutrition",
            '/principal/grades' => "/schools/$id/grades",
            '/principal/subjects' => "/schools/$id/subjects",
            '/principal/class-periods' => "/schools/$id/class-periods",
            '/principal/service-record' => "/schools/$id/service-records/" . $this->maria->getId(),
        ];
        $targets = [];

        // Execute
        foreach (array_keys($shortcuts) as $shortcut) {
            $this->client->request('GET', $shortcut);
            $targets[$shortcut] = $this->client->getResponse()->headers->get('Location');
        }

        // Assert
        self::assertSame($shortcuts, $targets);
    }

    public function testEveryShortcutShowsTheNotAssignedPage(): void
    {
        // Initialize
        $shortcuts = ['/principal/sections', '/principal/learners', '/principal/attendance', '/principal/nutrition',
            '/principal/grades', '/principal/subjects', '/principal/class-periods'];
        $texts = [];

        // Execute
        foreach ($shortcuts as $shortcut) {
            $this->client->request('GET', $shortcut);
            $texts[] = $this->client->getResponse()->getStatusCode() . ' '
                . trim($this->client->getCrawler()->filter('main .card.empty')->text());
        }

        // Assert
        $expected = '200 You are not assigned to a school for S.Y. 2026-2027. Contact the administrator.';
        self::assertSame(array_fill(0, \count($shortcuts), $expected), $texts);
    }

    public function testUnassignedPrincipalGetsAnEmptyNowCard(): void
    {
        // Execute
        $this->client->request('GET', '/principal/now');

        // Assert
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#now', 'Class periods are not set up yet.');
    }
}
