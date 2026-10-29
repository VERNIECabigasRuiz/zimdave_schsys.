<?php

namespace App\Tests\Controller;

use App\Entity\AttendanceMark;
use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\LearnerMark;
use App\Entity\RollCall;
use App\Entity\School;
use App\Entity\SchoolDayOff;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Sf2Submission;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class TeacherAttendanceControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $teacher;
    private Section $artistic;
    /** @var list<Enrollment> */
    private array $enrolled = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2025-08-22 10:00:00');
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->teacher = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->teacher, User::ROLE_TEACHER);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser($this->teacher);
        foreach (['Abad', 'Bautista', 'Cruz'] as $i => $last) {
            $enrollment = new Enrollment($this->learner('12001717000'.$i, $last, 'Ana'), $this->sy, $this->artistic, $this->teacher, new \DateTimeImmutable('2025-05-20'));
            $this->em->persist($enrollment);
            $this->enrolled[] = $enrollment;
        }
        $this->em->persist(new SchoolDayOff($this->school, $this->sy, new \DateTimeImmutable('2025-08-21'), 'Ninoy Aquino Day', $this->teacher, new \DateTimeImmutable()));
        $this->em->flush();
        $this->client->loginUser($this->teacher);
    }

    private function field(int $i): string
    {
        return 'roll_call_form[mark_'.$this->enrolled[$i]->getId().']';
    }

    /** @return array<int, LearnerMark> marks of the day by enrollment id */
    private function marksOn(string $date): array
    {
        $this->em->clear();
        $marks = [];
        foreach ($this->em->getRepository(AttendanceMark::class)->findAll() as $mark) {
            if ($mark->getRollCall()->getDate()->format('Y-m-d') === $date) {
                $marks[$mark->getEnrollment()->getId()] = $mark->getMark();
            }
        }

        return $marks;
    }

    public function testRollCallPageListsLearners(): void
    {
        $crawler = $this->client->request('GET', '/teacher/class/attendance');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Attendance');
        self::assertSelectorTextContains('main', 'Friday, August 22, 2025');
        foreach (['ABAD', 'BAUTISTA', 'CRUZ'] as $name) {
            self::assertSelectorTextContains('.roll-call', $name);
        }
        self::assertSame('', $crawler->filter('select[name="'.$this->field(0).'"]')->attr('value') ?? '');
        self::assertSelectorTextContains('select[name="'.$this->field(0).'"] option', 'Present');
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Attendance');
    }

    public function testSaveRollCall(): void
    {
        $this->client->request('GET', '/teacher/class/attendance');
        $form = $this->client->getCrawler()->selectButton('Save attendance')->form([$this->field(0) => 'absent', $this->field(1) => 'late']);
        $this->client->submit($form);

        self::assertResponseRedirects('/teacher/class/attendance/2025-08-22');
        self::assertSame([$this->enrolled[0]->getId() => LearnerMark::Absent, $this->enrolled[1]->getId() => LearnerMark::Late], $this->marksOn('2025-08-22'));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Attendance saved for Friday, August 22.');
        self::assertSelectorTextContains('.roll-call', '1 absent');
    }

    public function testEditRollCall(): void
    {
        $this->client->request('GET', '/teacher/class/attendance/2025-08-20');
        $this->client->submit($this->client->getCrawler()->selectButton('Save attendance')->form([$this->field(0) => 'absent']));
        $this->client->request('GET', '/teacher/class/attendance/2025-08-20');
        $this->client->submit($this->client->getCrawler()->selectButton('Save attendance')->form([$this->field(0) => '', $this->field(2) => 'cutting']));

        self::assertSame([$this->enrolled[2]->getId() => LearnerMark::Cutting], $this->marksOn('2025-08-20'));
        self::assertSame(1, $this->em->getRepository(RollCall::class)->count([]));
    }

    public function testRefusedDatesShowMessage(): void
    {
        $this->client->request('GET', '/teacher/class/attendance/2025-08-21');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'No classes: Ninoy Aquino Day.');
        self::assertSelectorNotExists('.roll-call select');

        $this->client->request('GET', '/teacher/class/attendance/2025-08-25');
        self::assertSelectorTextContains('main', "You can't take attendance for a future date.");

        $this->client->request('POST', '/teacher/class/attendance/2025-08-25', ['roll_call_form' => []]);
        self::assertSame(0, $this->em->getRepository(RollCall::class)->count([]));
    }

    public function testMonthGrid(): void
    {
        $this->client->request('GET', '/teacher/class/attendance/2025-08-20');
        $this->client->submit($this->client->getCrawler()->selectButton('Save attendance')->form([$this->field(0) => 'absent']));

        $crawler = $this->client->request('GET', '/teacher/class/attendance/month/2025-08');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'August 2025');
        self::assertSelectorTextContains('.sf2-grid', 'x');
        self::assertSelectorTextContains('main', 'Average Daily Attendance');
        self::assertSelectorTextContains('main', 'Aug 21 – Ninoy Aquino Day');
        self::assertCount(1, $crawler->filter('a[href="/teacher/class/attendance/month/2025-08/sf2"]'));
        self::assertCount(1, $crawler->filter('a[href="/teacher/class/attendance/2025-08-20"]'));
        // August hasn't ended, so it can't be submitted yet
        self::assertSelectorNotExists('form[name="sf2_submit_form"]');
    }

    public function testSf2Download(): void
    {
        $this->client->request('GET', '/teacher/class/attendance/month/2025-08/sf2');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        self::assertStringContainsString('SF2_2025_Grade 8 - ARTISTIC_August.xlsx', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testSubmitLocksTheMonth(): void
    {
        static::mockTime('2025-09-02 10:00:00');
        $crawler = $this->client->request('GET', '/teacher/class/attendance/month/2025-07');
        self::assertSelectorTextContains('form[name="sf2_submit_form"]', '23 school days have no attendance');

        $this->client->submit($crawler->selectButton('Submit SF2 for July')->form(['sf2_submit_form[confirmMissing]' => true]));

        self::assertResponseRedirects('/teacher/class/attendance/month/2025-07');
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Submitted');
        self::assertSame(1, $this->em->getRepository(Sf2Submission::class)->count([]));

        $this->client->request('GET', '/teacher/class/attendance/2025-07-15');
        self::assertSelectorTextContains('main', 'July 2025 is submitted and locked.');
        self::assertSelectorNotExists('.roll-call select');
    }

    public function testSubmitWithoutConfirmingMissingDaysIsRefused(): void
    {
        static::mockTime('2025-09-02 10:00:00');
        $crawler = $this->client->request('GET', '/teacher/class/attendance/month/2025-07');
        $this->client->submit($crawler->selectButton('Submit SF2 for July')->form());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--error', '23 school days have no attendance.');
        self::assertSame(0, $this->em->getRepository(Sf2Submission::class)->count([]));
    }

    public function testNonAdviserIs403(): void
    {
        $this->client->loginUser($other = $this->createUser('omar', User::ROLE_TEACHER));
        $this->assign($this->school, $this->sy, $other, User::ROLE_TEACHER);

        $this->client->request('GET', '/teacher/class/attendance');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/teacher/class/attendance/2025-08-22', ['roll_call_form' => []]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame(0, $this->em->getRepository(RollCall::class)->count([]));
    }

    public function testPrincipalCannotTake(): void
    {
        $this->client->loginUser($this->createUser('rene', User::ROLE_PRINCIPAL));

        $this->client->request('GET', '/teacher/class/attendance');
        self::assertResponseStatusCodeSame(403);
    }

    public function testDashboardShowsTodaysAttendance(): void
    {
        $this->client->request('GET', '/teacher');
        self::assertSelectorTextContains('.advisory-card', "Today's attendance: not taken yet");

        $this->client->request('GET', '/teacher/class/attendance');
        $this->client->submit($this->client->getCrawler()->selectButton('Save attendance')->form());
        $this->client->request('GET', '/teacher');
        self::assertSelectorTextContains('.advisory-card', "Today's attendance: taken");
    }
}
