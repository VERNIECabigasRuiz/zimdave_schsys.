<?php

namespace App\Tests\Controller;

use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolType;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\Subject;
use App\Entity\TeachingLoad;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SchoolSectionControllerTest extends AdminTestCase
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
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school, $this->sy, $this->maria);
        $this->client->loginUser($this->maria);
    }

    private function url(School $school, string $suffix = ''): string
    {
        return '/schools/'.$school->getId().'/sections'.$suffix;
    }

    private function teacher(string $username, School $school, ?SchoolYear $sy = null): User
    {
        $user = $this->createUser($username, User::ROLE_TEACHER, ucfirst($username).' Reyes');
        $this->assign($school, $sy ?? $this->sy, $user, User::ROLE_TEACHER);

        return $user;
    }

    private function section(GradeLevel $level, string $name, ?User $adviser = null, ?School $school = null, ?SchoolYear $sy = null): Section
    {
        $section = (new Section($school ?? $this->school, $sy ?? $this->sy))->setGradeLevel($level)->setName($name)->setAdviser($adviser);
        $this->em->persist($section);
        $this->em->flush();

        return $section;
    }

    /** @return list<string> */
    private function labels(): array
    {
        $this->em->clear();

        return array_map(static fn (Section $s) => $s->getLabel().($s->getAdviser() ? ' @'.$s->getAdviser()->getUsername() : ''), $this->em->getRepository(Section::class)->findBy([], ['gradeLevel' => 'ASC', 'name' => 'ASC']));
    }

    public function testListGroupedByGradeLevel(): void
    {
        $ana = $this->teacher('ana', $this->school);
        $this->section(GradeLevel::Grade8, 'Rosal');
        $this->section(GradeLevel::Grade7, 'Sampaguita', $ana);

        $crawler = $this->client->request('GET', $this->url($this->school));

        self::assertResponseIsSuccessful();
        self::assertSame(['Grade 7', 'Grade 8'], $crawler->filter('.section-group h2')->each(static fn ($h) => $h->text()));
        self::assertSelectorTextContains('.section-group', 'Sampaguita');
        self::assertSelectorTextContains('.section-group', 'Ana Reyes');
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Sections');
    }

    public function testSidebarLinkGoesToOwnSchool(): void
    {
        $this->client->request('GET', '/principal/sections');

        self::assertResponseRedirects($this->url($this->school));
    }

    public function testCreateSectionWithAdviser(): void
    {
        $ana = $this->teacher('ana', $this->school);
        $this->client->request('GET', $this->url($this->school, '/new'));

        $this->client->submitForm('Create section', [
            'section_form[gradeLevel]' => '7',
            'section_form[name]' => 'Sampaguita',
            'section_form[adviser]' => (string) $ana->getId(),
        ]);

        self::assertResponseRedirects($this->url($this->school));
        self::assertSame(['Grade 7 – Sampaguita @ana'], $this->labels());
        $log = $this->activityActions();
        self::assertContains('Created section Grade 7 – Sampaguita', $log);
        self::assertContains('Assigned adviser ana to Grade 7 – Sampaguita', $log);
    }

    public function testGradeLevelChoicesFollowSchoolType(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->school, '/new'));

        self::assertSame(['', '7', '8', '9', '10'], $crawler->filter('#section_form_gradeLevel option')->each(static fn ($o) => $o->attr('value')));
    }

    public function testAdviserChoicesAreOnlyCurrentTeachersOfTheSchool(): void
    {
        $this->teacher('ana', $this->school);
        $other = $this->school('303457', 'Other NHS');
        $ben = $this->teacher('ben', $other);
        $this->teacher('old', $this->school, $this->schoolYear(2025, current: false));

        $crawler = $this->client->request('GET', $this->url($this->school, '/new'));
        self::assertSame(['', 'Ana Reyes'], $crawler->filter('#section_form_adviser option')->each(static fn ($o) => $o->attr('value') ? $o->text() : ''));

        // Tampering with another school's teacher id is rejected
        $form = $crawler->selectButton('Create section')->form();
        $values = $form->getPhpValues();
        $values['section_form']['gradeLevel'] = '7';
        $values['section_form']['name'] = 'Sampaguita';
        $values['section_form']['adviser'] = (string) $ben->getId();
        $this->client->request('POST', $this->url($this->school, '/new'), $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->labels());
    }

    public function testSeniorHighSectionNeedsTrack(): void
    {
        $this->em->getRepository(School::class)->find($this->school->getId())->setType(SchoolType::Integrated);
        $this->em->flush();
        $this->client->request('GET', $this->url($this->school, '/new'));

        $this->client->submitForm('Create section', ['section_form[gradeLevel]' => '11', 'section_form[name]' => 'STEM 1']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Choose the track of this Senior High section.');

        $this->client->submitForm('Create section', [
            'section_form[gradeLevel]' => '11',
            'section_form[name]' => 'STEM 1',
            'section_form[track]' => 'academic',
            'section_form[strand]' => 'STEM',
        ]);
        self::assertResponseRedirects($this->url($this->school));
        self::assertSame(['Grade 11 – STEM 1 (Academic – STEM)'], $this->labels());
    }

    public function testEditChangesAdviserAndLogs(): void
    {
        $ana = $this->teacher('ana', $this->school);
        $ben = $this->teacher('ben', $this->school);
        $section = $this->section(GradeLevel::Grade7, 'Sampaguita', $ana);

        $this->client->request('GET', $this->url($this->school, '/'.$section->getId().'/edit'));
        $this->client->submitForm('Save section', ['section_form[adviser]' => (string) $ben->getId(), 'section_form[name]' => 'Sampaguita A']);

        self::assertResponseRedirects($this->url($this->school));
        self::assertSame(['Grade 7 – Sampaguita A @ben'], $this->labels());
        self::assertContains('Assigned adviser ben to Grade 7 – Sampaguita A', $this->activityActions());
    }

    public function testAdviserAlreadyAdvisingShowsError(): void
    {
        $ana = $this->teacher('ana', $this->school);
        $this->section(GradeLevel::Grade7, 'Sampaguita', $ana);
        $this->client->request('GET', $this->url($this->school, '/new'));

        $this->client->submitForm('Create section', ['section_form[gradeLevel]' => '8', 'section_form[name]' => 'Rosal', 'section_form[adviser]' => (string) $ana->getId()]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Ana Reyes is already the adviser of Grade 7 – Sampaguita.');
    }

    public function testGradeOfSectionWithTimetableCannotChange(): void
    {
        $section = $this->section(GradeLevel::Grade7, 'Sampaguita');
        $math = (new Subject($this->school, $this->sy))->setGradeLevel(GradeLevel::Grade7)->setCode('MATH7')->setName('Mathematics')->setMeetingsPerWeek(5);
        $this->em->persist($math);
        $this->em->persist(new TeachingLoad($section, $math, $this->teacher('ana', $this->school)));
        $this->em->flush();

        $this->client->request('GET', $this->url($this->school, '/'.$section->getId().'/edit'));
        $this->client->submitForm('Save section', ['section_form[gradeLevel]' => '8']);

        self::assertResponseRedirects($this->url($this->school));
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Grade 7 – Sampaguita has a timetable. Remove its classes and teachers first.');
        self::assertSame(['Grade 7 – Sampaguita'], $this->labels());
    }

    public function testDeleteSection(): void
    {
        $section = $this->section(GradeLevel::Grade7, 'Sampaguita');
        $crawler = $this->client->request('GET', $this->url($this->school));

        $this->client->submit($crawler->filter('tr:contains("Sampaguita")')->selectButton('Delete')->form());

        self::assertResponseRedirects($this->url($this->school));
        self::assertSame([], $this->labels());
        self::assertContains('Deleted section Grade 7 – Sampaguita', $this->activityActions());
    }

    public function testOtherSchoolAndTeacherAreForbidden(): void
    {
        $other = $this->school('303457', 'Other NHS');
        $section = $this->section(GradeLevel::Grade7, 'Rose', school: $other);
        $ana = $this->teacher('ana', $this->school);

        $this->client->request('GET', $this->url($other));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->url($this->school, '/'.$section->getId().'/edit'));
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($ana);
        $this->client->request('GET', $this->url($this->school));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminManagesAnySchoolAndHasLink(): void
    {
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/admin/schools');
        self::assertCount(1, $crawler->filter('a[href="'.$this->url($this->school).'"]'));

        $this->client->request('GET', $this->url($this->school, '/new'));
        $this->client->submitForm('Create section', ['section_form[gradeLevel]' => '9', 'section_form[name]' => 'Narra']);
        self::assertResponseRedirects($this->url($this->school));
        self::assertSame(['Grade 9 – Narra'], $this->labels());
    }

    public function testBringBackCopiesWithoutAdvisersAndSkipsExisting(): void
    {
        $last = $this->schoolYear(2025, current: false);
        $old = $this->teacher('old', $this->school, $last);
        $this->section(GradeLevel::Grade7, 'Sampaguita', $old, sy: $last);
        $this->section(GradeLevel::Grade8, 'Rosal', sy: $last);
        $this->section(GradeLevel::Grade8, 'Rosal');

        $crawler = $this->client->request('GET', $this->url($this->school));
        $this->client->submit($crawler->selectButton('Bring back sections from S.Y. 2025-2026')->form());

        self::assertResponseRedirects($this->url($this->school));
        $this->em->clear();
        $now = $this->em->getRepository(Section::class)->findBy(['schoolYear' => $this->sy->getId()], ['gradeLevel' => 'ASC']);
        self::assertSame(['Grade 7 – Sampaguita', 'Grade 8 – Rosal'], array_map(static fn (Section $s) => $s->getLabel(), $now));
        self::assertNull($now[0]->getAdviser());
        self::assertContains('Brought back 1 section(s) to S.Y. 2026-2027 for Pagsabungan NHS', $this->activityActions());

        $this->client->request('GET', $this->url($this->school));
        self::assertSelectorNotExists('button:contains("Bring back sections")');
    }

    public function testExpiredPlanIsViewOnly(): void
    {
        $section = $this->section(GradeLevel::Grade7, 'Sampaguita');
        $this->em->getRepository(School::class)->find($this->school->getId())->setPlanValidUntil(new \DateTimeImmutable('2026-10-15'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->school));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.plan-notice', 'expired on Oct 15, 2026');
        self::assertSelectorNotExists('a:contains("New section")');
        self::assertSelectorTextContains('.section-group', 'Sampaguita');

        $this->client->request('GET', $this->url($this->school, '/new'));
        self::assertResponseRedirects($this->url($this->school));
        $this->client->request('GET', $this->url($this->school, '/'.$section->getId().'/edit'));
        self::assertResponseRedirects($this->url($this->school));

        // Every write path in the service is refused, not just the buttons being hidden
        $manager = static::getContainer()->get(\App\Service\SectionManager::class);
        $last = $this->schoolYear(2025, current: false);
        $this->em->clear();
        $section = $this->em->getRepository(Section::class)->find($section->getId());
        $last = $this->em->getRepository(SchoolYear::class)->find($last->getId());
        foreach ([
            static fn () => $manager->delete($section),
            static fn () => $manager->save($section),
            static fn () => $manager->bringBack($section->getSchool(), $last, $section->getSchoolYear()),
        ] as $write) {
            try {
                $write();
                self::fail('Expected the expired plan to block the write');
            } catch (\App\Service\PlanLimitException $e) {
                self::assertStringContainsString('expired on Oct 15, 2026', $e->getMessage());
            }
        }
        self::assertSame(['Grade 7 – Sampaguita'], $this->labels());
    }
}
