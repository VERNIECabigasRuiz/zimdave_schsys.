<?php

namespace App\Tests\Controller;

use App\Learners\Domain\Model\Enrollment;
use App\Schools\Domain\Model\GradeLevel;
use App\Entity\LearnerMark;
use App\Entity\RollCall;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Entity\Sf2Submission;
use App\Access\Domain\Model\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SchoolAttendanceControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private School $other;
    private User $principal;
    private User $adviser;
    private Section $artistic;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2025-08-22 10:00:00');
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->other = $this->school('303197', 'Other NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->principal = $this->createUser('rene', User::ROLE_PRINCIPAL, 'Rene Petancio');
        $this->assign($this->school, $this->sy, $this->principal);
        $this->adviser = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->adviser, User::ROLE_TEACHER);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser($this->adviser);
        $this->em->persist(new Enrollment($this->learner('120017170001', 'Abad', 'Ana'), $this->sy, $this->artistic, $this->adviser, new \DateTimeImmutable('2025-05-20')));
        $this->em->flush();
        $this->client->loginUser($this->principal);
    }

    private function url(string $path = ''): string
    {
        return '/schools/'.$this->school->getId().$path;
    }

    public function testOverview(): void
    {
        $this->client->request('GET', $this->url('/attendance'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Attendance');
        self::assertSelectorTextContains('main', 'Grade 8 – Artistic');
        self::assertSelectorTextContains('main', 'Not taken yet');
        self::assertSelectorTextContains('main', 'Tess Reyes');
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Attendance');
    }

    public function testSectionMonthIsReadOnly(): void
    {
        $crawler = $this->client->request('GET', $this->url('/sections/'.$this->artistic->getId().'/attendance/2025-08'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'August 2025');
        self::assertSelectorNotExists('form[name="sf2_submit_form"]');
        self::assertCount(0, $crawler->filter('.sf2-grid__day a'));
        self::assertCount(1, $crawler->filter('a[href="'.$this->url('/sections/'.$this->artistic->getId().'/attendance/2025-08/sf2').'"]'));

        $this->client->request('GET', $this->url('/sections/'.$this->artistic->getId().'/attendance/2025-08/sf2'));
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function testReopen(): void
    {
        $this->em->persist(new Sf2Submission($this->artistic, new \DateTimeImmutable('2025-07-01'), $this->adviser, new \DateTimeImmutable('2025-08-02')));
        $this->em->flush();
        $path = '/sections/'.$this->artistic->getId().'/attendance/2025-07';

        $this->client->request('GET', $this->url($path));
        self::assertSelectorExists('.sf2-status button');

        $this->client->request('POST', $this->url($path.'/reopen'), ['_token' => $this->csrfToken('reopen-'.$this->artistic->getId())]);
        self::assertResponseRedirects($this->url($path));
        self::assertSame(0, $this->em->getRepository(Sf2Submission::class)->count([]));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'July 2025 reopened');
    }

    public function testPrincipalCannotTakeAttendance(): void
    {
        $this->client->request('POST', '/teacher/class/attendance/2025-08-22', ['roll_call_form' => []]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em->getRepository(RollCall::class)->count([]));
    }

    public function testAdminSeesTheOverview(): void
    {
        $this->client->loginUser($this->createUser('ana_admin', User::ROLE_ADMIN));

        $this->client->request('GET', $this->url('/attendance'));

        self::assertResponseIsSuccessful();
    }

    public function testAdviserCannotOpenTheSchoolPages(): void
    {
        $this->client->loginUser($this->adviser);

        $this->client->request('GET', $this->url('/attendance'));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->url('/calendar'));
        self::assertResponseStatusCodeSame(403);
    }

    public function testSectionOfAnotherSchoolIs404(): void
    {
        $elsewhere = $this->newSection($this->other, $this->sy, GradeLevel::Grade8, 'Elsewhere');

        $this->client->request('GET', $this->url('/sections/'.$elsewhere->getId().'/attendance/2025-08'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testPrincipalShortcuts(): void
    {
        $this->client->request('GET', '/principal/attendance');
        self::assertResponseRedirects($this->url('/attendance'));

        $this->client->request('GET', '/principal');
        self::assertSelectorTextContains('main', 'Students present today');
    }

    public function testDashboardTotalsStudentsPresentToday(): void
    {
        $absent = new Enrollment($this->learner('120017170002', 'Bautista', 'Ben'), $this->sy, $this->artistic, $this->adviser, new \DateTimeImmutable('2025-05-20'));
        $bonifacio = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Bonifacio');
        $this->em->persist($absent);
        $this->em->persist(new Enrollment($this->learner('120017170003', 'Cruz', 'Carla'), $this->sy, $bonifacio, $this->principal, new \DateTimeImmutable('2025-05-20')));
        $this->em->flush();
        $rollCall = new RollCall($this->artistic, new \DateTimeImmutable('2025-08-22'), $this->adviser, new \DateTimeImmutable());
        $rollCall->replaceMarks([$absent->getId() => [$absent, LearnerMark::Absent]]);
        $this->em->persist($rollCall);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/principal');

        $card = $crawler->filter('.stat')->reduce(static fn ($node) => str_contains($node->text(), 'present today'));
        self::assertSame('1 / 3', $card->filter('strong')->text());
        self::assertSame('Students present today · 1 absent', $card->filter('small')->first()->text());
        self::assertStringContainsString('1 section not taken yet', $card->text());
    }

    public function testDashboardShowsNoClassesOnADayOff(): void
    {
        $this->em->persist(new SchoolDayOff($this->school, $this->sy, new \DateTimeImmutable('2025-08-22'), 'Typhoon suspension', $this->principal, new \DateTimeImmutable()));
        $this->em->flush();

        $this->client->request('GET', '/principal');

        self::assertSelectorTextContains('main', 'No classes');
        self::assertSelectorTextContains('main', 'Typhoon suspension');
    }

    public function testClassListLinksToAttendance(): void
    {
        $crawler = $this->client->request('GET', $this->url('/sections/'.$this->artistic->getId().'/learners'));

        self::assertCount(1, $crawler->filter('a[href="'.$this->url('/sections/'.$this->artistic->getId().'/attendance').'"]'));
    }
}
