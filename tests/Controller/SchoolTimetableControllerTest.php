<?php

namespace App\Tests\Controller;

use App\Entity\ClassPeriod;
use App\Entity\GradeLevel;
use App\Entity\ScheduleEntry;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Entity\Weekday;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SchoolTimetableControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $maria;
    private User $santos;
    private User $reyes;
    private ClassPeriod $p1;
    private ClassPeriod $p2;
    private Section $mabini;
    private Subject $math8;
    private Subject $sci8;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-16');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school, $this->sy, $this->maria);
        $this->santos = $this->createUser('santos', User::ROLE_TEACHER, 'Ana Santos');
        $this->assign($this->school, $this->sy, $this->santos, User::ROLE_TEACHER);
        $this->reyes = $this->createUser('reyes', User::ROLE_TEACHER, 'Jose Reyes');
        $this->assign($this->school, $this->sy, $this->reyes, User::ROLE_TEACHER);
        $this->p1 = $this->classPeriod($this->school, $this->sy, '07:30', '08:30');
        $this->p2 = $this->classPeriod($this->school, $this->sy, '08:30', '09:30');
        $this->mabini = $this->section($this->school, GradeLevel::Grade8, 'Mabini');
        $this->math8 = $this->subject(GradeLevel::Grade8, 'MATH8', 'Mathematics');
        $this->sci8 = $this->subject(GradeLevel::Grade8, 'SCI8', 'Science');
        $this->client->loginUser($this->maria);
    }

    protected function tearDown(): void
    {
        // later test classes delete users without clearing school data first
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function section(School $school, GradeLevel $grade, string $name, ?SchoolYear $sy = null): Section
    {
        $section = (new Section($school, $sy ?? $this->sy))->setGradeLevel($grade)->setName($name);
        $this->em->persist($section);
        $this->em->flush();

        return $section;
    }

    private function subject(GradeLevel $grade, string $code, string $name, ?int $semester = null, ?int $perWeek = 5): Subject
    {
        $subject = (new Subject($this->school, $this->sy))->setGradeLevel($grade)->setCode($code)->setName($name)->setPosition(1)
            ->setMeetingsPerWeek($perWeek)->setSemester($semester);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function load(Section $section, Subject $subject, User $teacher): TeachingLoad
    {
        $load = new TeachingLoad($section, $subject, $teacher);
        $this->em->persist($load);
        $this->em->flush();

        return $load;
    }

    private function url(Section $section, string $suffix = '', ?School $school = null): string
    {
        return '/schools/'.($school ?? $this->school)->getId().'/sections/'.$section->getId().'/timetable'.$suffix;
    }

    private function token(?Section $section = null): string
    {
        $crawler = $this->client->request('GET', $this->url($section ?? $this->mabini));
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('[data-controller="timetable"]')->attr('data-timetable-csrf-value');
    }

    /** @param array<string, mixed>|null $body */
    private function json(string $method, string $url, ?array $body, ?string $token): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if (null !== $token) {
            $server['HTTP_X_CSRF_TOKEN'] = $token;
        }
        $this->client->request($method, $url, server: $server, content: null === $body ? '' : json_encode($body));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    public function testPageRendersGridForPrincipal(): void
    {
        $load = $this->load($this->mabini, $this->math8, $this->santos);
        $this->em->persist(new ScheduleEntry($load, Weekday::Tuesday, $this->p2));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->mabini));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Grade 8 – Mabini');
        $root = $crawler->filter('[data-controller="timetable"]');
        self::assertCount(1, $root);
        self::assertStringContainsString($this->url($this->mabini, '/state'), (string) $root->attr('data-timetable-state-url-value'));
        self::assertStringContainsString('/entries', (string) $root->attr('data-timetable-plot-url-value'));
        self::assertStringContainsString('__SUBJECT__', (string) $root->attr('data-timetable-load-url-value'));
        self::assertStringContainsString('__ENTRY__', (string) $root->attr('data-timetable-remove-url-value'));
        self::assertSame('0', $root->attr('data-timetable-term-value'));
        self::assertSame(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], $crawler->filter('.timetable-grid thead th:not(:first-child)')->each(static fn ($th) => trim($th->text())));
        self::assertCount(2, $crawler->filter('.timetable-grid tbody tr'));
        self::assertStringContainsString('Mathematics', $crawler->filter('.timetable-cell--filled')->text());
        self::assertStringContainsString('Ana Santos', $crawler->filter('.timetable-cell--filled')->text());
        foreach (['message', 'cards', 'grid', 'dialog', 'dialogTitle', 'choices'] as $target) {
            self::assertCount(1, $root->filter(\sprintf('[data-timetable-target="%s"]', $target)), $target);
        }
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Sections');
    }

    public function testNoPeriodsShowsSetupLink(): void
    {
        $this->em->createQuery('DELETE FROM '.ClassPeriod::class)->execute();

        $crawler = $this->client->request('GET', $this->url($this->mabini));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Set up your class periods first');
        self::assertSame('/schools/'.$this->school->getId().'/class-periods', $crawler->selectLink('Set up your class periods first')->attr('href'));
    }

    public function testAdminAllowed(): void
    {
        $this->client->loginUser($this->admin);
        $this->client->request('GET', $this->url($this->mabini));
        self::assertResponseIsSuccessful();
    }

    public function testOtherSchoolPrincipalDenied(): void
    {
        $other = $this->school('999999', 'Other NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $otherSection = $this->section($other, GradeLevel::Grade8, 'Luna');

        $this->client->request('GET', $this->url($otherSection, '', $other));
        self::assertResponseStatusCodeSame(403);
    }

    public function testTeacherDenied(): void
    {
        $this->client->loginUser($this->santos);
        $this->client->request('GET', $this->url($this->mabini));
        self::assertResponseStatusCodeSame(403);
    }

    public function testSectionOfOtherSchoolIs404(): void
    {
        $other = $this->school('999999', 'Other NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $otherSection = $this->section($other, GradeLevel::Grade8, 'Luna');

        $this->client->request('GET', $this->url($otherSection));
        self::assertResponseStatusCodeSame(404);

        $oldYear = $this->schoolYear(2025, current: false);
        $oldSection = $this->section($this->school, GradeLevel::Grade8, 'Old', $oldYear);
        $this->client->request('GET', $this->url($oldSection));
        self::assertResponseStatusCodeSame(404);
    }

    public function testPlotSavesAndReturnsState(): void
    {
        $this->load($this->mabini, $this->math8, $this->santos);
        $token = $this->token();

        $state = $this->json('POST', $this->url($this->mabini, '/entries'), ['subjectId' => $this->math8->getId(), 'day' => 1, 'periodId' => $this->p1->getId()], $token);

        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $state['cells']);
        self::assertSame($this->math8->getId(), $state['cells'][0]['subjectId']);
        self::assertSame(1, $state['cells'][0]['day']);
        self::assertSame(1, $state['cards'][0]['used']);
        self::assertSame(1, $this->em->getRepository(ScheduleEntry::class)->count([]));
    }

    public function testPlotClashReturns422WithMessage(): void
    {
        $this->load($this->mabini, $this->math8, $this->santos);
        $this->load($this->mabini, $this->sci8, $this->reyes);
        $token = $this->token();
        $url = $this->url($this->mabini, '/entries');
        $this->json('POST', $url, ['subjectId' => $this->math8->getId(), 'day' => 1, 'periodId' => $this->p1->getId()], $token);

        $body = $this->json('POST', $url, ['subjectId' => $this->sci8->getId(), 'day' => 1, 'periodId' => $this->p1->getId()], $token);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Grade 8 – Mabini already has Mathematics on Mon, Period 1.', $body['message']);
    }

    public function testPlotInvalidDayReturns422(): void
    {
        $this->load($this->mabini, $this->math8, $this->santos);
        $token = $this->token();

        $this->json('POST', $this->url($this->mabini, '/entries'), ['subjectId' => $this->math8->getId(), 'day' => 6, 'periodId' => $this->p1->getId()], $token);

        self::assertResponseStatusCodeSame(422);
    }

    public function testPlotUnknownSubjectIs404(): void
    {
        $token = $this->token();

        $this->json('POST', $this->url($this->mabini, '/entries'), ['subjectId' => 999999, 'day' => 1, 'periodId' => $this->p1->getId()], $token);

        self::assertResponseStatusCodeSame(404);
    }

    public function testWriteWithoutCsrfRejected(): void
    {
        $this->load($this->mabini, $this->math8, $this->santos);

        $this->json('POST', $this->url($this->mabini, '/entries'), ['subjectId' => $this->math8->getId(), 'day' => 1, 'periodId' => $this->p1->getId()], null);
        // Symfony reports an invalid CSRF token as an authentication failure: redirect to the login page
        self::assertResponseRedirects('http://localhost/login');

        $this->json('POST', $this->url($this->mabini, '/entries'), ['subjectId' => $this->math8->getId(), 'day' => 1, 'periodId' => $this->p1->getId()], 'bad-token');
        self::assertResponseRedirects('http://localhost/login');
        self::assertSame(0, $this->em->getRepository(ScheduleEntry::class)->count([]));
    }

    public function testSetTeacherAndClear(): void
    {
        $token = $this->token();
        $url = $this->url($this->mabini, '/loads/'.$this->math8->getId());

        $state = $this->json('PUT', $url, ['teacherId' => $this->santos->getId()], $token);
        self::assertResponseStatusCodeSame(200);
        $card = array_values(array_filter($state['cards'], fn ($c) => $c['subjectId'] === $this->math8->getId()))[0];
        self::assertSame($this->santos->getId(), $card['teacherId']);

        $state = $this->json('PUT', $url, ['teacherId' => null], $token);
        self::assertResponseStatusCodeSame(200);
        $card = array_values(array_filter($state['cards'], fn ($c) => $c['subjectId'] === $this->math8->getId()))[0];
        self::assertNull($card['teacherId']);
        self::assertSame(0, $this->em->getRepository(TeachingLoad::class)->count([]));
    }

    public function testSetTeacherOfAnotherSchoolIs404(): void
    {
        $outsider = $this->createUser('outsider', User::ROLE_TEACHER, 'Out Sider');
        $token = $this->token();

        $this->json('PUT', $this->url($this->mabini, '/loads/'.$this->math8->getId()), ['teacherId' => $outsider->getId()], $token);

        self::assertResponseStatusCodeSame(404);
    }

    public function testRemoveEntry(): void
    {
        $load = $this->load($this->mabini, $this->math8, $this->santos);
        $entry = new ScheduleEntry($load, Weekday::Monday, $this->p1);
        $this->em->persist($entry);
        $this->em->flush();
        $token = $this->token();

        $state = $this->json('DELETE', $this->url($this->mabini, '/entries/'.$entry->getId()), null, $token);

        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $state['cells']);
        self::assertSame(0, $this->em->getRepository(ScheduleEntry::class)->count([]));
    }

    public function testStateEndpointBusyIsObject(): void
    {
        $this->client->request('GET', $this->url($this->mabini, '/state'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('"busy":{}', (string) $this->client->getResponse()->getContent());
    }

    public function testShsTermSwitch(): void
    {
        $this->em->createQuery('DELETE FROM '.Subject::class)->execute();
        $school = $this->school;
        $school->setType(\App\Entity\SchoolType::SeniorHigh);
        $stem = $this->section($school, GradeLevel::Grade11, 'STEM-A');
        $s1 = $this->subject(GradeLevel::Grade11, 'ORAL', 'Oral Communication', 1);
        $s2 = $this->subject(GradeLevel::Grade11, 'READ', 'Reading and Writing', 2);
        $this->load($stem, $s1, $this->santos);
        $this->load($stem, $s2, $this->reyes);

        $crawler = $this->client->request('GET', $this->url($stem, '?term=2'));

        self::assertResponseIsSuccessful();
        self::assertSame('2', $crawler->filter('[data-controller="timetable"]')->attr('data-timetable-term-value'));
        $cards = $crawler->filter('.timetable-card')->each(static fn ($c) => $c->text());
        self::assertCount(1, $cards);
        self::assertStringContainsString('Reading and Writing', $cards[0]);
        self::assertStringNotContainsString('Oral Communication', $crawler->filter('main, body')->first()->html());
        self::assertSame(['1st sem', '2nd sem'], $crawler->filter('[data-timetable-target="terms"] a')->each(static fn ($a) => trim($a->text())));
    }
}
