<?php

namespace App\Tests\Schools\Functional;

use App\Entity\Enrollment;
use App\Schools\Domain\Model\GradeLevel;
use App\Entity\RollCall;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Access\Domain\Model\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SchoolCalendarControllerTest extends AdminTestCase
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
        $this->school = $this->school(
            '303196',
            'Pagsabungan NHS',
            planValidUntil: new \DateTimeImmutable('2026-04-30'),
        );
        $this->other = $this->school('303197', 'Other NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->principal = $this->createUser('rene', User::ROLE_PRINCIPAL, 'Rene Petancio');
        $this->assign($this->school, $this->sy, $this->principal);
        $this->adviser = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->adviser, User::ROLE_TEACHER);
        $this->artistic = $this->newSection(
            $this->school,
            $this->sy,
            GradeLevel::Grade8,
            'Artistic',
        )->setAdviser($this->adviser);
        $this->em->persist(new Enrollment(
            $this->learner('120017170001', 'Abad', 'Ana'),
            $this->sy,
            $this->artistic,
            $this->adviser,
            new \DateTimeImmutable('2025-05-20'),
        ));
        $this->em->flush();
        $this->client->loginUser($this->principal);
    }

    private function url(string $path = ''): string
    {
        return '/schools/' . $this->school->getId() . $path;
    }

    public function testCalendarAddsARangeAndRemoves(): void
    {
        $this->client->request('GET', $this->url('/calendar'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Attendance');

        $form = $this->client->getCrawler()->selectButton('Add days off')->form([
            'day_off_form[from]' => '2025-12-19',
            'day_off_form[to]' => '2026-01-02',
            'day_off_form[reason]' => 'Christmas break',
        ]);
        $this->client->submit($form);
        self::assertResponseRedirects($this->url('/calendar'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', '11 days off added.');
        self::assertSelectorTextContains('main', 'Christmas break');
        self::assertSame(11, $this->em->getRepository(SchoolDayOff::class)->count([]));

        $dayOff = $this->em->getRepository(SchoolDayOff::class)
            ->findOneBy(['date' => new \DateTimeImmutable('2025-12-19')]);
        $this->client->request(
            'POST',
            $this->url('/calendar/' . $dayOff?->getId() . '/remove'),
            ['_token' => $this->csrfToken('day-off-' . $dayOff?->getId())],
        );
        self::assertResponseRedirects($this->url('/calendar'));
        self::assertSame(10, $this->em->getRepository(SchoolDayOff::class)->count([]));
    }

    public function testCalendarWarnsAboutTakenAttendance(): void
    {
        $this->em->persist(new RollCall(
            $this->artistic,
            new \DateTimeImmutable('2025-08-20'),
            $this->adviser,
            new \DateTimeImmutable(),
        ));
        $this->em->flush();

        $this->client->request('GET', $this->url('/calendar'));
        $this->client->submit($this->client->getCrawler()->selectButton('Add days off')->form([
            'day_off_form[from]' => '2025-08-20',
            'day_off_form[reason]' => 'Typhoon suspension',
        ]));
        $this->client->followRedirect();

        self::assertSelectorTextContains('.alert--success', '1 day off added.');
        self::assertSelectorTextContains(
            '.alert--warning',
            '1 section already took attendance on those days; those days no longer count.',
        );
    }

    public function testCalendarRefusesAnotherSchoolsDayOff(): void
    {
        $dayOff = new SchoolDayOff(
            $this->other,
            $this->sy,
            new \DateTimeImmutable('2025-08-20'),
            'Foundation Day',
            $this->principal,
            new \DateTimeImmutable(),
        );
        $this->em->persist($dayOff);
        $this->em->flush();
        $this->client->request('GET', $this->url('/calendar'));

        $this->client->request(
            'POST',
            $this->url('/calendar/' . $dayOff->getId() . '/remove'),
            ['_token' => $this->csrfToken('day-off-' . $dayOff->getId())],
        );

        self::assertResponseStatusCodeSame(404);
    }

    public function testBadRangeShowsTheReason(): void
    {
        // Initialize
        $this->client->request('GET', $this->url('/calendar'));
        $form = $this->client->getCrawler()->selectButton('Add days off')->form([
            'day_off_form[from]' => '2025-09-10',
            'day_off_form[to]' => '2025-09-08',
            'day_off_form[reason]' => 'Something',
        ]);

        // Execute
        $this->client->submit($form);

        // Assert
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert--error', 'The end date must be on or after the start date.');
        self::assertSame(0, $this->em->getRepository(SchoolDayOff::class)->count([]));
    }

    public function testWithoutCurrentYearIs404ForTheAdmin(): void
    {
        // Initialize
        $this->sy->setCurrent(false);
        $this->em->flush();
        $this->client->loginUser($this->admin);

        // Execute
        $this->client->request('GET', $this->url('/calendar'));

        // Assert
        self::assertResponseStatusCodeSame(404);
    }
}
