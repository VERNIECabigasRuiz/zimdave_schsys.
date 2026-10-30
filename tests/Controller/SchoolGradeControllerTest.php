<?php

namespace App\Tests\Controller;

use App\Entity\Enrollment;
use App\Entity\Grade;
use App\Entity\GradeLevel;
use App\Entity\GradeLock;
use App\Entity\GradeUnlock;
use App\Entity\GradingPeriod;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Crawler;

class SchoolGradeControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private School $other;
    private User $principal;
    private User $teacher;
    private Section $honest;
    private Subject $math;
    private Subject $science;
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
        $this->term1->setOpen(true);
        $this->em->flush();
        $this->school = $this->school('303196', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->other = $this->school('303197', 'Other NHS', planValidUntil: new \DateTimeImmutable('2026-04-30'));
        $this->principal = $this->createUser('rosa', User::ROLE_PRINCIPAL, 'Rosa Cruz');
        $this->assign($this->school, $this->sy, $this->principal);
        $this->teacher = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->teacher, User::ROLE_TEACHER);
        $this->honest = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Honest');
        $this->math = $this->subject(GradeLevel::Grade8, 'MATH8', 'Mathematics', 1);
        $this->science = $this->subject(GradeLevel::Grade8, 'SCI8', 'Science', 2);
        $this->em->persist(new TeachingLoad($this->honest, $this->math, $this->teacher));
        foreach (['Abad' => SexAtBirth::Female, 'Bautista' => SexAtBirth::Male] as $last => $sex) {
            $learner = $this->learner('1200171700'.\count($this->enrollments), $last, 'Ana')->setSex($sex);
            $this->em->persist($this->enrollments[$last] = new Enrollment($learner, $this->sy, $this->honest, $this->teacher, new \DateTimeImmutable('2025-05-20')));
        }
        $this->em->flush();
        $this->client->loginUser($this->principal);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function subject(GradeLevel $level, string $code, string $name, int $position, ?Subject $parent = null): Subject
    {
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel($level)->setCode($code)->setName($name)->setParent($parent)->setPosition($position);
        if (null === $parent) {
            $subject->setMeetingsPerWeek(5);
        }
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function url(string $path = ''): string
    {
        return '/schools/'.$this->school->getId().$path;
    }

    private function overviewUrl(): string
    {
        return $this->url('/grades?period='.$this->term1->getId());
    }

    private function classUrl(Subject $subject, string $action): string
    {
        return $this->url(\sprintf('/sections/%d/subjects/%d/grades/%d/%s', $this->honest->getId(), $subject->getId(), $this->term1->getId(), $action));
    }

    private function classRow(Crawler $crawler, Subject $subject): Crawler
    {
        return $crawler->filter(\sprintf('.grade-classes tr[data-class="%d-%d"]', $this->honest->getId(), $subject->getId()));
    }

    private function grade(string $last, Subject $subject, int $value): void
    {
        $this->em->persist(new Grade($this->enrollments[$last], $subject, $this->term1, $value, $this->teacher, new \DateTimeImmutable('2025-08-21')));
        $this->em->flush();
    }

    private function lockTerm1(): Crawler
    {
        $crawler = $this->client->request('GET', $this->overviewUrl());

        return $this->client->submit($crawler->selectButton('Lock Term 1')->form(['grade_lock_form[confirm]' => true]));
    }

    private function isLocked(): bool
    {
        $this->em->clear();

        return 1 === $this->em->getRepository(GradeLock::class)->count([]);
    }

    private function unlockCount(): int
    {
        $this->em->clear();

        return $this->em->getRepository(GradeUnlock::class)->count([]);
    }

    public function testOverview(): void
    {
        $mapeh = $this->subject(GradeLevel::Grade8, 'MAPEH8', 'MAPEH', 3);
        $music = $this->subject(GradeLevel::Grade8, 'MUS8', 'Music', 1, $mapeh);
        $this->newSection($this->school, $this->sy, GradeLevel::Kinder, 'Daisy');
        $this->subject(GradeLevel::Kinder, 'K', 'Kindergarten', 1);
        $this->grade('Abad', $this->math, 90);
        $this->grade('Bautista', $this->math, 85);
        $this->grade('Abad', $this->science, 80);

        $crawler = $this->client->request('GET', $this->url('/grades'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Grades');
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Grades');
        self::assertSelectorTextContains('.grade-lock-status', 'Open · not locked');
        self::assertSelectorTextContains('.grade-tabs', 'Term 2');

        $math = $this->classRow($crawler, $this->math);
        self::assertCount(1, $math);
        self::assertStringContainsString('Grade 8 – Honest', $math->text());
        self::assertStringContainsString('Tess Reyes', $math->text());
        self::assertStringContainsString('2 / 2', $math->text());
        self::assertStringContainsString('Open', $math->filter('.grade-status')->text());
        $science = $this->classRow($crawler, $this->science);
        self::assertStringContainsString('1 / 2', $science->text());
        self::assertStringContainsString('—', $science->text());
        self::assertCount(1, $this->classRow($crawler, $music));
        self::assertCount(0, $this->classRow($crawler, $mapeh));
        self::assertStringNotContainsString('Daisy', $crawler->filter('.grade-classes')->text());
        self::assertSelectorTextContains('.grade-summary', '1 of 3 classes complete');
        self::assertCount(1, $math->filter('a[href="'.$this->url('/sections/'.$this->honest->getId().'/grades?period='.$this->term1->getId()).'"]'));
        // no per-class buttons while the period isn't locked
        self::assertCount(0, $math->filter('button'));
    }

    public function testLockUnlockClassRelockAndUnlockPeriod(): void
    {
        $this->lockTerm1();
        self::assertResponseRedirects($this->overviewUrl());
        self::assertTrue($this->isLocked());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Term 1 grades are locked.');
        self::assertSelectorTextContains('.grade-lock-status', 'Locked on Aug 22, 2025 by Rosa Cruz');
        self::assertSame('Locked', $this->classRow($crawler, $this->math)->filter('.grade-status')->text());
        self::assertSame('Locked', $this->classRow($crawler, $this->science)->filter('.grade-status')->text());

        $this->client->submit($this->classRow($crawler, $this->math)->selectButton('Unlock')->form());
        self::assertResponseRedirects($this->overviewUrl());
        self::assertSame(1, $this->unlockCount());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Mathematics – Grade 8 – HONEST is unlocked for corrections.');
        self::assertSame('Unlocked for corrections', $this->classRow($crawler, $this->math)->filter('.grade-status')->text());
        self::assertSame('Locked', $this->classRow($crawler, $this->science)->filter('.grade-status')->text());

        $this->client->submit($this->classRow($crawler, $this->math)->selectButton('Lock again')->form());
        self::assertResponseRedirects($this->overviewUrl());
        self::assertSame(0, $this->unlockCount());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Mathematics – Grade 8 – HONEST is locked again.');
        self::assertSame('Locked', $this->classRow($crawler, $this->math)->filter('.grade-status')->text());

        $this->client->submit($crawler->selectButton('Unlock Term 1')->form());
        self::assertResponseRedirects($this->overviewUrl());
        self::assertFalse($this->isLocked());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Term 1 grades are unlocked.');
        self::assertSame('Open', $this->classRow($crawler, $this->math)->filter('.grade-status')->text());
    }

    public function testLockNeedsTheConfirmBox(): void
    {
        $crawler = $this->client->request('GET', $this->overviewUrl());
        self::assertSelectorTextContains('form[name="grade_lock_form"]', 'Yes, lock every class in Term 1');

        $this->client->submit($crawler->selectButton('Lock Term 1')->form());

        self::assertResponseRedirects($this->overviewUrl());
        self::assertFalse($this->isLocked());
        $this->client->followRedirect();
        self::assertSelectorExists('.alert--error');
    }

    public function testLockRefusedWhenNeverOpened(): void
    {
        $this->em->find(GradingPeriod::class, $this->term1->getId())->setOpen(false);
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->overviewUrl());
        self::assertSelectorTextContains('.grade-lock-status', 'Closed for grading');
        $this->client->submit($crawler->selectButton('Lock Term 1')->form(['grade_lock_form[confirm]' => true]));

        self::assertFalse($this->isLocked());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--error', "Term 1 hasn't been opened for grading yet.");
    }

    public function testRefusalsBecomeFlashes(): void
    {
        $this->client->request('GET', $this->overviewUrl());
        $this->client->request('POST', $this->classUrl($this->math, 'unlock'), ['_token' => $this->csrfToken(\sprintf('grade-unlock-class-%d-%d-%d', $this->honest->getId(), $this->math->getId(), $this->term1->getId()))]);
        self::assertResponseRedirects($this->overviewUrl());
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--error', 'Term 1 is not locked.');

        $this->client->request('POST', $this->url('/grades/'.$this->term1->getId().'/unlock'), ['_token' => $this->csrfToken('grade-unlock-'.$this->term1->getId())]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--error', 'Term 1 is not locked.');
    }

    public function testBadTokenChangesNothing(): void
    {
        $this->lockTerm1();
        $this->client->request('POST', $this->classUrl($this->math, 'unlock'), ['_token' => 'nope']);
        self::assertSame(0, $this->unlockCount());

        $this->client->request('POST', $this->url('/grades/'.$this->term1->getId().'/unlock'), ['_token' => 'nope']);
        self::assertTrue($this->isLocked());
    }

    public function testAdminViewsButCannotLock(): void
    {
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', $this->overviewUrl());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $this->classRow($crawler, $this->math));
        self::assertSelectorNotExists('form[name="grade_lock_form"]');
        self::assertCount(0, $crawler->filter('main button'));

        $this->client->request('POST', $this->url('/grades/'.$this->term1->getId().'/lock'), ['grade_lock_form' => ['confirm' => '1']]);
        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->isLocked());
        $this->client->request('POST', $this->classUrl($this->math, 'unlock'), ['_token' => $this->csrfToken(\sprintf('grade-unlock-class-%d-%d-%d', $this->honest->getId(), $this->math->getId(), $this->term1->getId()))]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesNoButtonsWhileLocked(): void
    {
        $this->lockTerm1();
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', $this->overviewUrl());

        self::assertSelectorTextContains('.grade-lock-status', 'Locked on Aug 22, 2025 by Rosa Cruz');
        self::assertCount(0, $crawler->filter('main button'));
    }

    public function testSectionGrid(): void
    {
        $this->grade('Abad', $this->math, 90);

        $crawler = $this->client->request('GET', $this->url('/sections/'.$this->honest->getId().'/grades'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Grade 8 – Honest');
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Grades');
        self::assertSelectorTextContains('.grade-grid thead', 'Mathematics');
        self::assertSelectorTextContains('.grade-grid thead', '1 / 2');
        self::assertCount(0, $crawler->filter('main input'));
        self::assertCount(1, $crawler->filter('a[href="'.$this->url('/sections/'.$this->honest->getId().'/grades?period='.$this->sy->getGradingPeriod(2)->getId()).'"]'));
    }

    public function testTeacherIs403(): void
    {
        $this->client->loginUser($this->teacher);

        $this->client->request('GET', $this->url('/grades'));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->url('/sections/'.$this->honest->getId().'/grades'));
        self::assertResponseStatusCodeSame(403);
    }

    public function testOtherSchoolsSectionIs404(): void
    {
        $elsewhere = $this->newSection($this->other, $this->sy, GradeLevel::Grade8, 'Elsewhere');

        $this->client->request('GET', $this->url('/sections/'.$elsewhere->getId().'/grades'));
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', $this->overviewUrl());
        $this->client->request('POST', $this->url(\sprintf('/sections/%d/subjects/%d/grades/%d/unlock', $elsewhere->getId(), $this->math->getId(), $this->term1->getId())), ['_token' => $this->csrfToken(\sprintf('grade-unlock-class-%d-%d-%d', $elsewhere->getId(), $this->math->getId(), $this->term1->getId()))]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testUnknownPeriodIs404(): void
    {
        $this->client->request('GET', $this->url('/grades?period=999999'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testPrincipalShortcutAndMenu(): void
    {
        $this->client->request('GET', '/principal/grades');
        self::assertResponseRedirects($this->url('/grades'));

        $crawler = $this->client->request('GET', '/principal');
        self::assertCount(1, $crawler->filter('.sidebar__link:contains("Grades")'));
    }

    public function testAdminSchoolListLinksToGrades(): void
    {
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/admin/schools');

        self::assertCount(1, $crawler->filter('a[href="'.$this->url('/grades').'"]'));
    }
}
