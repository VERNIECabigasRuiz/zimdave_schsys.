<?php

namespace App\Tests\Controller\Admin;

use App\Entity\User;
use App\Service\ActivityLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class DashboardControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    public function testAdminIsSentToDashboardAfterSignIn(): void
    {
        $this->client->request('GET', '/');

        self::assertResponseRedirects('/admin');
    }

    public function testDashboardShowsTotalsAndRecentActivity(): void
    {
        $this->createUser('teacher1', User::ROLE_TEACHER);
        $this->createUser('teacher2', User::ROLE_TEACHER);
        $this->createUser('student1', User::ROLE_STUDENT);
        $this->createUser('student2', User::ROLE_STUDENT, active: false);
        $this->createUser('principal1', User::ROLE_PRINCIPAL);

        static::getContainer()->get(ActivityLogger::class)->log('Created account: teacher1', 'zim');
        $this->em->flush();

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Dashboard');

        $stats = $crawler->filter('.stats--totals .stat')->each(static fn ($node) => $node->filter('small')->text().'='.$node->filter('strong')->text());
        self::assertSame(['Accounts=6', 'Principals=1', 'Teachers=2', 'Students=2', 'Inactive=1'], $stats);

        self::assertSelectorTextContains('.table tbody', 'Created account: teacher1');
    }

    public function testSidebarHasAllMenuItems(): void
    {
        $crawler = $this->client->request('GET', '/admin');

        self::assertSame(
            ['Dashboard', 'User Accounts', 'School Year', 'Schools', 'Plans', 'PRINCIPAL', 'TEACHER', 'STUDENT', 'ANNOUNCEMENTS'],
            $crawler->filter('.sidebar__link')->each(static fn ($a) => $a->text()),
        );
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Dashboard');
    }

    #[DataProvider('adminPages')]
    public function testNonAdminsCannotOpenAdminPages(string $url): void
    {
        foreach ([User::ROLE_PRINCIPAL, User::ROLE_TEACHER, User::ROLE_STUDENT] as $i => $role) {
            $this->client->loginUser($this->createUser('user'.$i, $role));
            $this->client->request('GET', $url);
            self::assertResponseStatusCodeSame(403, $role.' should not open '.$url);
        }
    }

    public static function adminPages(): iterable
    {
        yield ['/admin'];
        yield ['/admin/accounts'];
        yield ['/admin/accounts/new'];
        yield ['/admin/teachers'];
        yield ['/admin/school-years'];
        yield ['/admin/schools'];
        yield ['/admin/plans'];
        yield ['/admin/announcements'];
    }

    public function testDashboardShowsSchoolsWithoutPrincipal(): void
    {
        $sy = $this->schoolYear(2026, current: true);
        $assigned = $this->school('300001', 'Assigned School');
        $this->school('300002', 'Open School A');
        $this->school('300003', 'Open School B');
        $this->school('300004', 'Closed School', active: false);
        $this->assign($assigned, $sy, $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos'));

        $crawler = $this->client->request('GET', '/admin');

        $card = $crawler->filter('.stat:contains("Schools without a principal")');
        self::assertCount(1, $card);
        self::assertSame('2', $card->filter('strong')->text());
        self::assertSame('/admin/schools', $card->attr('href'));
    }

    public function testDashboardShowsPrincipalStatsForCurrentSchoolYear(): void
    {
        $sy = $this->schoolYear(2026, current: true);
        $other = $this->schoolYear(2025, current: false);
        $first = $this->school('300001', 'First School');
        $second = $this->school('300002', 'Second School');
        $this->school('300003', 'Third School');
        $this->school('300004', 'Closed School', active: false);
        $this->assign($first, $sy, $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos'));
        $this->assign($second, $sy, $this->createUser('juan', User::ROLE_PRINCIPAL, 'Juan Cruz'));
        // Assigned only in another year, so unassigned for 2026-2027
        $this->assign($first, $other, $this->createUser('pedro', User::ROLE_PRINCIPAL, 'Pedro Lim'));
        $this->createUser('ana', User::ROLE_PRINCIPAL, 'Ana Reyes');
        $this->createUser('gone', User::ROLE_PRINCIPAL, 'Gone Principal', active: false);

        $crawler = $this->client->request('GET', '/admin');

        $year = $crawler->filter('.stats--year');
        self::assertCount(1, $year);
        self::assertStringContainsString('S.Y. 2026-2027', $crawler->filter('.stats-heading')->text());
        $stats = $year->filter('.stat')->each(static fn ($node) => $node->filter('small')->text().'='.$node->filter('strong')->text());
        self::assertSame([
            'Schools=3',
            'Principals assigned=2',
            'Principals unassigned=2',
            'Schools without a principal=1',
        ], $stats);
    }

    public function testPlansExpiringCard(): void
    {
        static::mockTime('2026-10-16');
        $this->school('300001', 'Expired School', planValidUntil: new \DateTimeImmutable('2026-10-15'));
        $this->school('300002', 'Expiring School', planValidUntil: new \DateTimeImmutable('2026-10-26'));
        $this->school('300003', 'Paid School', planValidUntil: new \DateTimeImmutable('2026-12-15'));
        $this->school('300004', 'Forever School');
        $this->school('300005', 'Closed School', active: false, planValidUntil: new \DateTimeImmutable('2026-01-01'));

        $crawler = $this->client->request('GET', '/admin');

        $card = $crawler->filter('.stats--plans .stat:contains("Plans expired or expiring in 30 days")');
        self::assertCount(1, $card);
        self::assertSame('2', $card->filter('strong')->text());
        self::assertSame('/admin/schools', $card->attr('href'));
    }

    public function testDashboardWithoutCurrentSchoolYear(): void
    {
        $this->school('300001', 'Some School');

        $crawler = $this->client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.stats--year'));
    }
}
