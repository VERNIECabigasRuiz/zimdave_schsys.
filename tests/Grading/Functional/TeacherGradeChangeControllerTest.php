<?php

declare(strict_types=1);

namespace App\Tests\Grading\Functional;

use Symfony\Component\Lock\LockFactory;
use App\Tests\Grading\Integration\Application\SavesGrades;
use App\Learners\Domain\Model\Enrollment;
use App\Grading\Domain\Model\Grade;
use App\Grading\Domain\Model\GradeChangeRequest;
use App\Schools\Domain\Model\GradeLevel;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\SexAtBirth;
use App\Classes\Domain\Model\Subject;
use App\Classes\Domain\Model\TeachingLoad;
use App\Access\Domain\Model\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

class TeacherGradeChangeControllerTest extends AdminTestCase
{
    use SavesGrades;

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
        $this->term1 = $this->sy->getGradingPeriod(1) ?? throw new \LogicException('No period 1');
        $this->em->flush();
        $this->school = $this->school(
            '303196',
            'Pagsabungan NHS',
            planValidUntil: new \DateTimeImmutable('2026-04-30'),
        );
        $this->openGrading($this->school, $this->term1);
        $this->teacher = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->teacher, User::ROLE_TEACHER);
        $this->principal = $this->createUser('rosa', User::ROLE_PRINCIPAL, 'Rosa Cruz');
        $this->assign($this->school, $this->sy, $this->principal);
        $this->honest = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Honest');
        $this->math = $this->subject(GradeLevel::Grade8, 'MATH8', 'Mathematics');
        $this->em->persist($this->load = new TeachingLoad($this->honest, $this->math, $this->teacher));
        $sexes = ['Abad' => SexAtBirth::Female, 'Bautista' => SexAtBirth::Male, 'Cruz' => SexAtBirth::Female];
        foreach ($sexes as $last => $sex) {
            $learner = $this->learner('1200171700' . \count($this->enrollments), $last, 'Ana')->setSex($sex);
            $this->em->persist(
                $this->enrollments[$last] = new Enrollment(
                    $learner,
                    $this->sy,
                    $this->honest,
                    $this->teacher,
                    new \DateTimeImmutable('2025-05-20'),
                ),
            );
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
        $subject = (new Subject($this->school, $this->sy))
            ->setGradeLevel($level)
            ->setCode($code)
            ->setName($name)
            ->setPosition($position);
        $subject->setMeetingsPerWeek(5);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function sheetUrl(?GradingPeriod $period = null, ?TeachingLoad $load = null): string
    {
        return \sprintf('/teacher/grades/%d/%d', ($load ?? $this->load)->getId(), ($period ?? $this->term1)->getId());
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
        $this->savingRecorder()->lockPeriod(
            $this->found($em, School::class, $this->school->getId()),
            $this->found($em, GradingPeriod::class, $this->term1->getId()),
            $this->found($em, User::class, $this->principal->getId()),
        );
    }

    private function changeUrl(): string
    {
        return $this->sheetUrl() . '/change';
    }

    private function gradeAbad78AndLock(): void
    {
        $this->em->persist(
            new Grade(
                $this->enrollments['Abad'],
                $this->math,
                $this->term1,
                78,
                $this->teacher,
                new \DateTimeImmutable('2025-08-21'),
            ),
        );
        $this->em->flush();
        $this->lockTerm1();
    }

    /** @param array<string, string> $grades by last name */
    private function sendChange(Crawler $page, array $grades, string $reason): Crawler
    {
        $values = ['grade_change_form[reason]' => $reason];
        foreach ($grades as $last => $grade) {
            $values['grade_change_form[grades][' . $this->enrollments[$last]->getId() . ']'] = $grade;
        }

        return $this->client->submit($page->selectButton('Send to principal')->form($values));
    }

    private function sendViaManager(): GradeChangeRequest
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);

        return $this->savingChangeManager()->request(
            $this->found($em, TeachingLoad::class, $this->load->getId()),
            $this->found($em, GradingPeriod::class, $this->term1->getId()),
            [(int) $this->enrollments['Abad']->getId() => 82],
            "Encoding error on Abad's quiz total",
            $this->found($em, User::class, $this->teacher->getId()),
        );
    }

    private function requestCount(): int
    {
        $this->em->clear();

        return \count($this->em->getRepository(GradeChangeRequest::class)->findAll());
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function found(EntityManagerInterface $em, string $class, mixed $id): object
    {
        $entity = $em->find($class, $id);
        self::assertNotNull($entity);

        return $entity;
    }

    public function testARequestSentWhileTheClassIsBeingChangedAnswersWithTheBusyMessage(): void
    {
        // Initialize
        $this->gradeAbad78AndLock();
        $crawler = $this->client->request('GET', $this->changeUrl());
        $lock = static::getContainer()->get(LockFactory::class)
            ->createLock('grades-' . $this->honest->getId() . '-' . $this->math->getId());
        $lock->acquire();

        // Execute
        $this->sendChange($crawler, ['Abad' => '82'], "Encoding error on Abad's quiz total");
        $lock->release();

        // Assert
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString(
            'Another change is being saved. Please try again.',
            (string) $this->client->getResponse()->getContent(),
        );
        self::assertSame(0, $this->requestCount());
    }

    public function testATeacherWhoIsNoLongerATeacherCannotRequestAChange(): void
    {
        // Initialize
        $this->gradeAbad78AndLock();
        $teacher = $this->found($this->em, User::class, $this->teacher->getId());
        $teacher->setRole(User::ROLE_PRINCIPAL);
        $this->em->flush();
        $this->client->loginUser($teacher);

        // Execute
        $this->client->request('GET', $this->changeUrl());

        // Assert
        self::assertResponseStatusCodeSame(403);
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
        self::assertSelectorTextNotContains(
            '.grade-status',
            'A change request for this class is waiting for the principal.',
        );
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
            self::assertSame(
                1,
                substr_count(
                    (string) $this->client->getResponse()->getContent(),
                    'Write the reason for the change (at least 10 characters).',
                ),
            );
        }
        self::assertSame(0, $this->requestCount());
    }

    public function testChangeFormRefusedWhenClassListChanged(): void
    {
        $this->gradeAbad78AndLock();
        $crawler = $this->client->request('GET', $this->changeUrl());
        $this->enroll(
            $this->learner('120017170099', 'Dela Cruz', 'Ben'),
            $this->sy,
            $this->found($this->em, Section::class, $this->honest->getId()),
            $this->teacher,
        );

        $this->sendChange($crawler, ['Abad' => '82'], 'Encoding error on the quiz');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains(
            'main',
            'The class list changed while you were editing. Check the rows and save again.',
        );
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

    public function testWithdrawingARequestDecidedMeanwhileIsRefused(): void
    {
        // Initialize
        $this->gradeAbad78AndLock();
        $request = $this->sendViaManager();
        $crawler = $this->client->request('GET', $this->sheetUrl());
        $form = $crawler->selectButton('Withdraw')->form();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->savingChangeManager()->decline(
            $this->found($em, GradeChangeRequest::class, $request->getId()),
            $this->found($em, User::class, $this->principal->getId()),
            'Attach the class record',
        );

        // Execute
        $this->client->submit($form);

        // Assert
        self::assertResponseRedirects($this->sheetUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--error', 'This request has already been decided.');
    }

    /** Tess sent the request, then the class moved to Owen. */
    private function handLoadToOwen(): User
    {
        $owen = $this->createUser('owen', User::ROLE_TEACHER, 'Owen Lim');
        $this->assign($this->school, $this->sy, $owen, User::ROLE_TEACHER);
        $this->found($this->em, TeachingLoad::class, $this->load->getId())->setTeacher($owen);
        $this->em->flush();

        return $owen;
    }

    public function testWithdrawAfterTheClassMovedGoesToMyClasses(): void
    {
        $this->gradeAbad78AndLock();
        $request = $this->sendViaManager();
        $this->handLoadToOwen();
        $this->client->request('GET', '/teacher/grades');

        $this->client->request(
            'POST',
            '/teacher/grades/changes/' . $request->getId() . '/withdraw',
            ['_token' => $this->csrfToken('grade-change-withdraw-' . $request->getId())],
        );

        self::assertResponseRedirects('/teacher/grades');
        $this->client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert--success', 'Your change request was withdrawn.');
        $this->em->clear();
        self::assertFalse($this->found($this->em, GradeChangeRequest::class, $request->getId())->isPending());
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
        $this->client->request(
            'POST',
            '/teacher/grades/changes/' . $request->getId() . '/withdraw',
            ['_token' => $this->csrfToken('grade-change-withdraw-' . $request->getId())],
        );
        self::assertResponseStatusCodeSame(403);
        $this->em->clear();
        self::assertTrue($this->found($this->em, GradeChangeRequest::class, $request->getId())->isPending());
    }
}
