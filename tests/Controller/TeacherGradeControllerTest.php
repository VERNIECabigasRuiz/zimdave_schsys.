<?php

namespace App\Tests\Controller;

use App\Entity\Enrollment;
use App\Entity\Grade;
use App\Entity\GradeChangeRequest;
use App\Entity\GradeLevel;
use App\Entity\GradeOpening;
use App\Entity\GradeUnlock;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Sf1Remark;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\TransferRequest;
use App\Entity\TransferStatus;
use App\Entity\User;
use App\Grading\GradeChangeManager;
use App\Grading\GradeRecorder;
use App\Tests\Controller\Admin\AdminTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

class TeacherGradeControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $teacher;
    private User $principal;
    private Section $honest;
    private Subject $math;
    private TeachingLoad $load;
    private GradingPeriod $term1;
    /** @var array<string, Enrollment> by last name */
    private array $enrollments = [];

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2025-08-22 10:00:00');
        $this->sy = $this->schoolYear(2025, current: true);
        $this->sy->rebuildGradingPeriods();
        $this->term1 = $this->sy->getGradingPeriod(1);
        $this->em->flush();
        $this->school = $this->school('303196', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->openGrading($this->school, $this->term1);
        $this->teacher = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->teacher, User::ROLE_TEACHER);
        $this->principal = $this->createUser('rosa', User::ROLE_PRINCIPAL, 'Rosa Cruz');
        $this->assign($this->school, $this->sy, $this->principal);
        $this->honest = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Honest');
        $this->math = $this->subject(GradeLevel::Grade8, 'MATH8', 'Mathematics');
        $this->em->persist($this->load = new TeachingLoad($this->honest, $this->math, $this->teacher));
        foreach (['Abad' => SexAtBirth::Female, 'Bautista' => SexAtBirth::Male, 'Cruz' => SexAtBirth::Female] as $last => $sex) {
            $learner = $this->learner('1200171700'.\count($this->enrollments), $last, 'Ana')->setSex($sex);
            $this->em->persist($this->enrollments[$last] = new Enrollment($learner, $this->sy, $this->honest, $this->teacher, new \DateTimeImmutable('2025-05-20')));
        }
        $this->em->flush();
        $this->client->loginUser($this->teacher);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function subject(GradeLevel $level, string $code, string $name, int $position = 1): Subject
    {
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel($level)->setCode($code)->setName($name)->setPosition($position);
        $subject->setMeetingsPerWeek(5);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function sheetUrl(?GradingPeriod $period = null, ?TeachingLoad $load = null): string
    {
        return \sprintf('/teacher/grades/%d/%d', ($load ?? $this->load)->getId(), ($period ?? $this->term1)->getId());
    }

    private static function field(Enrollment $enrollment): string
    {
        return 'grade_sheet_form[grades]['.$enrollment->getId().']';
    }

    /** @param array<string, string> $grades by last name */
    private function post(Crawler $page, array $grades): Crawler
    {
        $values = [];
        foreach ($grades as $last => $grade) {
            $values[self::field($this->enrollments[$last])] = $grade;
        }

        return $this->client->submit($page->selectButton('Save grades')->form($values));
    }

    /** @return array<string, int> grade values by learner last name */
    private function grades(): array
    {
        $this->em->clear();
        $values = [];
        foreach ($this->em->getRepository(Grade::class)->findAll() as $grade) {
            $values[$grade->getEnrollment()->getLearner()->getLastName()] = $grade->getValue();
        }
        ksort($values);

        return $values;
    }

    private function lockTerm1(): void
    {
        // the recorder works with the container's current entity manager
        $em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(GradeRecorder::class)->lockPeriod(
            $em->find(School::class, $this->school->getId()),
            $em->find(GradingPeriod::class, $this->term1->getId()),
            $em->find(User::class, $this->principal->getId()),
        );
    }

    public function testOverviewListsLoadsAndStatuses(): void
    {
        $kinder = $this->newSection($this->school, $this->sy, GradeLevel::Kinder, 'Daisy');
        $this->em->persist(new TeachingLoad($kinder, $this->subject(GradeLevel::Kinder, 'K', 'Kindergarten'), $this->teacher));
        $science = $this->subject(GradeLevel::Grade8, 'SCI8', 'Science', 2);
        $this->em->persist(new TeachingLoad($this->honest, $science, $this->teacher));
        $mapeh = $this->subject(GradeLevel::Grade8, 'MAPEH8', 'MAPEH', 3);
        $this->em->persist((new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade8)->setCode('MUS8')->setName('Music')->setParent($mapeh)->setPosition(1));
        $this->em->persist(new TeachingLoad($this->honest, $mapeh, $this->teacher));
        $this->em->flush();
        $this->em->persist(new Grade($this->enrollments['Abad'], $this->math, $this->term1, 90, $this->teacher, new \DateTimeImmutable('2025-08-21')));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/teacher/grades');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.sidebar__link.is-active', 'My Grades');
        $rows = $crawler->filter('.grade-loads tbody tr');
        self::assertCount(2, $rows);
        self::assertStringContainsString('Mathematics', $rows->eq(0)->text());
        self::assertStringContainsString('Grade 8 – Honest', $rows->eq(0)->text());
        self::assertStringContainsString('Science', $rows->eq(1)->text());
        self::assertStringContainsString('1 / 3', $rows->eq(0)->text());
        self::assertStringContainsString('Open', $rows->eq(0)->text());
        self::assertStringContainsString('Closed', $rows->eq(0)->text());
        self::assertCount(1, $crawler->filter('a[href="'.$this->sheetUrl().'"]'));
        self::assertSelectorTextContains('.grade-loads thead', 'Term 1');
        self::assertSelectorTextContains('.grade-loads thead', 'Term 3');

        $ungraded = $crawler->filter('.grade-loads--ungraded');
        self::assertStringContainsString('Not graded here yet', $crawler->filter('main')->text());
        self::assertStringContainsString('Kindergarten – Daisy', $ungraded->text());
        self::assertStringContainsString('MAPEH', $ungraded->text());
        self::assertStringContainsString("Kinder, Senior High and subjects graded through their components aren't graded here yet.", $crawler->filter('main')->text());
        self::assertCount(0, $ungraded->filter('a'));

        $this->lockTerm1();
        $crawler = $this->client->request('GET', '/teacher/grades');
        self::assertStringContainsString('Locked', $crawler->filter('.grade-loads tbody tr')->eq(0)->text());
    }

    public function testSaveSheet(): void
    {
        $crawler = $this->client->request('GET', $this->sheetUrl());
        self::assertResponseIsSuccessful();
        // males first
        self::assertSame(['BAUTISTA, ANA, -', 'ABAD, ANA, -', 'CRUZ, ANA, -'], $crawler->filter('.grade-sheet tbody td.grade-sheet__name')->each(static fn (Crawler $td) => $td->text()));
        self::assertCount(3, $crawler->filter('.grade-sheet input[name^="grade_sheet_form[grades]"]'));
        self::assertSelectorTextContains('.grade-tabs', 'Term 2');

        $this->post($crawler, ['Abad' => '90', 'Bautista' => '85']);

        self::assertResponseRedirects($this->sheetUrl());
        self::assertSame(['Abad' => 90, 'Bautista' => 85], $this->grades());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Saved Term 1 grades for 2 learners.');
        self::assertSame('90', $crawler->filter('input[name="'.self::field($this->enrollments['Abad']).'"]')->attr('value'));

        // clearing a grade removes it
        $this->post($crawler, ['Abad' => '']);
        self::assertSame(['Bautista' => 85], $this->grades());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Saved Term 1 grades for 1 learner.');
    }

    public function testOutOfRange(): void
    {
        $crawler = $this->client->request('GET', $this->sheetUrl());
        $this->post($crawler, ['Abad' => '59', 'Bautista' => '101', 'Cruz' => '80']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(2, $this->client->getCrawler()->filter('.grade-sheet li:contains("Enter a grade from 60 to 100.")'));
        self::assertSame([], $this->grades());
    }

    public function testSaveRefusedWhenClosedWhileEditing(): void
    {
        $crawler = $this->client->request('GET', $this->sheetUrl());
        static::getContainer()->get(GradeRecorder::class)->closePeriod($this->school, $this->term1, $this->principal);

        $this->post($crawler, ['Abad' => '90']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.grade-sheet', "Term 1 isn't open for grading.");
        self::assertSame([], $this->grades());
    }

    public function testLockedWhileEditing(): void
    {
        $crawler = $this->client->request('GET', $this->sheetUrl());
        $this->lockTerm1();

        $this->post($crawler, ['Abad' => '90']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.grade-sheet', 'Term 1 is locked by the principal.');
        self::assertSame([], $this->grades());

        // a fresh look is read-only with the reason
        $crawler = $this->client->request('GET', $this->sheetUrl());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.grade-status', 'Term 1 is locked by the principal.');
        self::assertCount(0, $crawler->filter('.grade-sheet input'));
        self::assertCount(0, $crawler->filter('button:contains("Save grades")'));
    }

    public function testClosedPeriodIsReadOnly(): void
    {
        $crawler = $this->client->request('GET', $this->sheetUrl($this->sy->getGradingPeriod(2)));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.grade-status', "Term 2 isn't open for grading.");
        self::assertCount(0, $crawler->filter('.grade-sheet input'));
    }

    public function testUnlockedClassEditable(): void
    {
        $this->lockTerm1();
        $this->em->persist(new GradeUnlock(
            $this->em->find(Section::class, $this->honest->getId()),
            $this->em->find(Subject::class, $this->math->getId()),
            $this->em->find(GradingPeriod::class, $this->term1->getId()),
            $this->em->find(User::class, $this->principal->getId()),
            new \DateTimeImmutable('2025-08-22 10:00'),
        ));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->sheetUrl());
        self::assertSelectorTextContains('.grade-status', 'Unlocked for corrections');
        $this->post($crawler, ['Cruz' => '88']);

        self::assertResponseRedirects($this->sheetUrl());
        self::assertSame(['Cruz' => 88], $this->grades());
    }

    public function testClassListChanged(): void
    {
        $crawler = $this->client->request('GET', $this->sheetUrl());
        $cruz = $this->em->find(Enrollment::class, $this->enrollments['Cruz']->getId());
        $cruz->setRemarkCode(Sf1Remark::Dropped)->setRemarkDetails('b.1 Illness')->setRemarkDate(new \DateTimeImmutable('2025-08-20'));
        $this->em->flush();

        $this->post($crawler, ['Abad' => '90', 'Cruz' => '80']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.grade-sheet', 'The class list changed while you were editing. Check the rows and save again.');
        self::assertSame([], $this->grades());
        // the dropped learner is greyed with the status
        self::assertSelectorTextContains('.grade-sheet .roll-call__out', 'Dropped');
    }

    public function testTransferredOutLearnerKeepsTheGradeReadOnly(): void
    {
        $abad = $this->enrollments['Abad'];
        $this->em->persist(new Grade($abad, $this->math, $this->term1, 91, $this->teacher, new \DateTimeImmutable('2025-08-21')));
        // as TransferManager::approve does: the same enrollment row moves to the receiving section
        $to = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Brilliant');
        $request = new TransferRequest($abad, $to, $this->teacher, new \DateTimeImmutable('2025-08-22 08:00'));
        $request->decide(TransferStatus::Approved, $this->principal, new \DateTimeImmutable('2025-08-22 09:00'));
        $abad->moveTo($to);
        $this->em->persist($request);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->sheetUrl());

        self::assertResponseIsSuccessful();
        $row = $crawler->filter('.grade-sheet tr.roll-call__out');
        self::assertCount(1, $row);
        self::assertStringContainsString('Transferred out', $row->text());
        self::assertStringContainsString('91', $row->text());
        self::assertCount(0, $row->filter('input'));
        self::assertCount(0, $crawler->filter('input[name="'.self::field($abad).'"]'));
    }

    public function testOtherTeacherIs403(): void
    {
        $other = $this->createUser('owen', User::ROLE_TEACHER, 'Owen Lim');
        $this->assign($this->school, $this->sy, $other, User::ROLE_TEACHER);
        $this->client->loginUser($other);

        $this->client->request('GET', $this->sheetUrl());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->sheetUrl(), ['grade_sheet_form' => ['grades' => [$this->enrollments['Abad']->getId() => '90']]]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->grades());

        $this->client->request('GET', '/teacher/grades');
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('a[href="'.$this->sheetUrl().'"]');
    }

    public function testStudentIs403(): void
    {
        $this->client->loginUser($this->createUser('stud', User::ROLE_STUDENT));

        $this->client->request('GET', '/teacher/grades');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->sheetUrl());
        self::assertResponseStatusCodeSame(403);
    }

    public function testPeriodOfAnotherYearIs404(): void
    {
        $lastYear = $this->schoolYear(2024, current: false);
        $lastYear->rebuildGradingPeriods();
        $this->em->flush();

        $this->client->request('GET', $this->sheetUrl($lastYear->getGradingPeriod(1)));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/teacher/grades/999999/'.$this->term1->getId());
        self::assertResponseStatusCodeSame(404);
    }

    private function changeUrl(): string
    {
        return $this->sheetUrl().'/change';
    }

    private function gradeAbad78AndLock(): void
    {
        $this->em->persist(new Grade($this->enrollments['Abad'], $this->math, $this->term1, 78, $this->teacher, new \DateTimeImmutable('2025-08-21')));
        $this->em->flush();
        $this->lockTerm1();
    }

    /** @param array<string, string> $grades by last name */
    private function sendChange(Crawler $page, array $grades, string $reason): Crawler
    {
        $values = ['grade_change_form[reason]' => $reason];
        foreach ($grades as $last => $grade) {
            $values['grade_change_form[grades]['.$this->enrollments[$last]->getId().']'] = $grade;
        }

        return $this->client->submit($page->selectButton('Send to principal')->form($values));
    }

    private function sendViaManager(): GradeChangeRequest
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return static::getContainer()->get(GradeChangeManager::class)->request(
            $em->find(TeachingLoad::class, $this->load->getId()),
            $em->find(GradingPeriod::class, $this->term1->getId()),
            [(int) $this->enrollments['Abad']->getId() => 82],
            "Encoding error on Abad's quiz total",
            $em->find(User::class, $this->teacher->getId()),
        );
    }

    private function requestCount(): int
    {
        $this->em->clear();

        return \count($this->em->getRepository(GradeChangeRequest::class)->findAll());
    }

    public function testRequestButtonOnlyWhenLocked(): void
    {
        $crawler = $this->client->request('GET', $this->sheetUrl());
        self::assertCount(0, $crawler->filter('a:contains("Request grade change")'));

        $this->lockTerm1();
        $crawler = $this->client->request('GET', $this->sheetUrl());
        self::assertSame($this->changeUrl(), $crawler->filter('a:contains("Request grade change")')->attr('href'));
    }

    public function testSendChangeRequest(): void
    {
        $this->gradeAbad78AndLock();

        $crawler = $this->client->request('GET', $this->changeUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('78', $crawler->filter('tr:contains("ABAD")')->text());
        self::assertCount(3, $crawler->filter('input[name^="grade_change_form[grades]"]'));

        $this->sendChange($crawler, ['Abad' => '82'], "Encoding error on Abad's quiz total");

        self::assertResponseRedirects($this->sheetUrl());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Your change request was sent to the principal.');
        self::assertSelectorTextContains('.grade-status', 'Your change request is waiting for the principal.');
        self::assertSelectorTextNotContains('.grade-status', 'A change request for this class is waiting for the principal.');
        $card = $crawler->filter('.change-requests');
        self::assertStringContainsString('ABAD', $card->text());
        self::assertStringContainsString('78 → 82', $card->text());
        self::assertStringContainsString('Pending', $card->filter('.badge')->text());
        self::assertStringContainsString("Encoding error on Abad's quiz total", $card->text());
        self::assertCount(0, $crawler->filter('a:contains("Request grade change")'));
        // the grade itself waits for the principal
        self::assertSame(['Abad' => 78], $this->grades());

        // a second one is refused while the first waits
        $this->client->request('GET', $this->changeUrl());
        self::assertResponseRedirects($this->sheetUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'You already have a pending change request for this class.');
    }

    public function testChangeFormValidation(): void
    {
        $this->gradeAbad78AndLock();
        $crawler = $this->client->request('GET', $this->changeUrl());

        $this->sendChange($crawler, ['Abad' => '82'], 'short');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Write the reason for the change (at least 10 characters).');

        $this->sendChange($crawler, ['Abad' => '101'], 'Encoding error on the quiz');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Enter a grade from 60 to 100.');

        // the current grade again is no change
        $this->sendChange($crawler, ['Abad' => '78'], 'Encoding error on the quiz');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Enter at least one corrected grade.');

        self::assertSame(0, $this->requestCount());
    }

    public function testBlankReasonIsReportedOnce(): void
    {
        $this->gradeAbad78AndLock();
        $crawler = $this->client->request('GET', $this->changeUrl());

        foreach (['', "   \n  "] as $reason) {
            $this->sendChange($crawler, ['Abad' => '82'], $reason);
            self::assertResponseStatusCodeSame(422);
            self::assertSame(1, substr_count($this->client->getResponse()->getContent(), 'Write the reason for the change (at least 10 characters).'));
        }
        self::assertSame(0, $this->requestCount());
    }

    public function testChangeFormRefusedWhenClassListChanged(): void
    {
        $this->gradeAbad78AndLock();
        $crawler = $this->client->request('GET', $this->changeUrl());
        $this->enroll($this->learner('120017170099', 'Dela Cruz', 'Ben'), $this->sy, $this->em->find(Section::class, $this->honest->getId()), $this->teacher);

        $this->sendChange($crawler, ['Abad' => '82'], 'Encoding error on the quiz');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'The class list changed while you were editing. Check the rows and save again.');
        self::assertSame(0, $this->requestCount());
    }

    public function testChangeFormRedirectsWhenNotLocked(): void
    {
        $this->client->request('GET', $this->changeUrl());

        self::assertResponseRedirects($this->sheetUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', "Term 1 isn't locked — change the grades on the sheet.");
    }

    public function testWithdraw(): void
    {
        $this->gradeAbad78AndLock();
        $this->sendViaManager();

        $crawler = $this->client->request('GET', $this->sheetUrl());
        $this->client->submit($crawler->selectButton('Withdraw')->form());

        self::assertResponseRedirects($this->sheetUrl());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Your change request was withdrawn.');
        self::assertStringContainsString('Withdrawn', $crawler->filter('.change-requests .badge')->text());
        self::assertCount(0, $crawler->filter('.change-requests button:contains("Withdraw")'));
        self::assertCount(1, $crawler->filter('a:contains("Request grade change")'));
    }

    /** Tess sent the request, then the class moved to Owen. */
    private function handLoadToOwen(): User
    {
        $owen = $this->createUser('owen', User::ROLE_TEACHER, 'Owen Lim');
        $this->assign($this->school, $this->sy, $owen, User::ROLE_TEACHER);
        $this->em->find(TeachingLoad::class, $this->load->getId())->setTeacher($owen);
        $this->em->flush();

        return $owen;
    }

    public function testTeacherWhoTookOverSeesTheNeutralPendingLine(): void
    {
        $this->gradeAbad78AndLock();
        $this->sendViaManager();
        $this->client->loginUser($this->handLoadToOwen());

        $this->client->request('GET', $this->sheetUrl());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.grade-status', 'A change request for this class is waiting for the principal.');
        self::assertSelectorTextNotContains('.grade-status', 'Your change request is waiting for the principal.');
    }

    public function testWithdrawAfterTheClassMovedGoesToMyClasses(): void
    {
        $this->gradeAbad78AndLock();
        $request = $this->sendViaManager();
        $this->handLoadToOwen();
        $this->client->request('GET', '/teacher/grades');

        $this->client->request('POST', '/teacher/grades/changes/'.$request->getId().'/withdraw', ['_token' => $this->csrfToken('grade-change-withdraw-'.$request->getId())]);

        self::assertResponseRedirects('/teacher/grades');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert--success', 'Your change request was withdrawn.');
        $this->em->clear();
        self::assertFalse($this->em->find(GradeChangeRequest::class, $request->getId())->isPending());
    }

    public function testDeclinedShowsNote(): void
    {
        $this->gradeAbad78AndLock();
        $request = $this->sendViaManager();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        static::getContainer()->get(GradeChangeManager::class)->decline($request, $em->find(User::class, $this->principal->getId()), 'Attach the class record, please.');

        $crawler = $this->client->request('GET', $this->sheetUrl());

        $card = $crawler->filter('.change-requests');
        self::assertStringContainsString('Declined', $card->filter('.badge')->text());
        self::assertStringContainsString('Attach the class record, please.', $card->text());
        self::assertCount(1, $crawler->filter('a:contains("Request grade change")'));
    }

    public function testOtherTeacherCannotRequestOrWithdraw(): void
    {
        $this->gradeAbad78AndLock();
        $request = $this->sendViaManager();
        $other = $this->createUser('owen', User::ROLE_TEACHER, 'Owen Lim');
        $this->assign($this->school, $this->sy, $other, User::ROLE_TEACHER);
        $this->client->loginUser($other);

        $this->client->request('GET', $this->changeUrl());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', '/teacher/grades/changes/'.$request->getId().'/withdraw', ['_token' => $this->csrfToken('grade-change-withdraw-'.$request->getId())]);
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertTrue($this->em->find(GradeChangeRequest::class, $request->getId())->isPending());
    }

    private function adviser(): User
    {
        $ada = $this->createUser('ada', User::ROLE_TEACHER, 'Ada Lim');
        $this->assign($this->school, $this->sy, $ada, User::ROLE_TEACHER);
        $this->honest->setAdviser($ada);
        $this->em->flush();

        return $ada;
    }

    public function testAdviserSeesTheClassGradesGridWithBlanksHighlighted(): void
    {
        $this->client->loginUser($this->adviser());
        $this->em->persist(new Grade($this->enrollments['Abad'], $this->math, $this->term1, 91, $this->teacher, new \DateTimeImmutable('2025-08-21')));
        $this->em->flush();

        $crawler = $this->client->request('GET', '/teacher/class/grades');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.grade-grid thead', 'Mathematics');
        self::assertSelectorTextContains('.grade-grid thead', 'Tess Reyes');
        self::assertSelectorTextContains('.grade-grid thead', '1 / 3');
        self::assertCount(3, $crawler->filter('.grade-grid tbody tr[data-learner]'));
        self::assertSelectorTextContains('.grade-grid', '91');
        self::assertCount(2, $crawler->filter('.grade-grid td.grade-grid__blank'));
        self::assertSelectorTextContains('.grade-tabs .btn--primary', 'Term 1');
        // the period tabs point back at this page
        self::assertSelectorExists('.grade-tabs a[href*="/teacher/class/grades?period="]');
    }

    public function testClassGradesPeriodDefaultsToTheOpenOne(): void
    {
        $this->client->loginUser($this->adviser());
        $this->em->remove($this->em->getRepository(GradeOpening::class)->findOneBy(['school' => $this->school, 'gradingPeriod' => $this->term1]));
        $this->em->flush();
        $term2 = $this->sy->getGradingPeriod(2);
        $this->openGrading($this->school, $term2);
        // another school having Term 1 open doesn't move this school's grid
        $this->openGrading($this->school('303197', 'Other NHS'), $this->term1);

        $this->client->request('GET', '/teacher/class/grades');
        self::assertSelectorTextContains('.grade-tabs .btn--primary', $term2->getLabel());

        $this->client->request('GET', '/teacher/class/grades?period='.$this->term1->getId());
        self::assertSelectorTextContains('.grade-tabs .btn--primary', $this->term1->getLabel());
    }

    public function testClassGradesAreRefusedToANonAdviser(): void
    {
        // Tess teaches the class but does not advise it
        $this->client->request('GET', '/teacher/class/grades');

        self::assertResponseStatusCodeSame(403);
    }

    public function testClassListOffersTheClassGradesButtonToTheAdviser(): void
    {
        $this->client->loginUser($this->adviser());

        $this->client->request('GET', '/teacher/class');

        self::assertSelectorExists('a[href="/teacher/class/grades"]');
    }
}
