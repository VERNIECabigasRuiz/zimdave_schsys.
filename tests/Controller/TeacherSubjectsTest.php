<?php

namespace App\Tests\Controller;

use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolType;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\ShsTrack;
use App\Entity\Subject;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

/** On a plan without a principal (Individual) the teacher sets up their own section's subjects. */
class TeacherSubjectsTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $own;
    private User $ana;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-16');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->own = $this->school('303999', 'Ana Home Class', plan: 'Individual', planValidUntil: new \DateTimeImmutable('2027-04-30'))->setType(SchoolType::Integrated);
        $this->ana = $this->createUser('ana', User::ROLE_TEACHER, 'Ana Reyes');
        $this->assign($this->own, $this->sy, $this->ana, User::ROLE_TEACHER);
        $this->client->loginUser($this->ana);
    }

    private function advise(GradeLevel $grade, string $name, ?ShsTrack $track = null, ?string $strand = null): void
    {
        $this->em->persist((new Section($this->own, $this->sy))->setGradeLevel($grade)->setName($name)->setTrack($track)->setStrand($strand)->setAdviser($this->ana));
        $this->em->flush();
    }

    private function subject(GradeLevel $grade, string $code, string $name, ?SchoolYear $sy = null): Subject
    {
        $subject = (new Subject($this->own, $sy ?? $this->sy))->setGradeLevel($grade)->setCode($code)->setName($name)->setMeetingsPerWeek(5);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function find(string $code): ?Subject
    {
        $this->em->clear();

        return $this->em->getRepository(Subject::class)->findOneBy(['code' => $code]);
    }

    public function testDashboardLinkAndListLimitedToAdvisoryGrade(): void
    {
        $this->advise(GradeLevel::Grade7, 'Narra');
        $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics');
        $this->subject(GradeLevel::Grade8, 'MATH8', 'Mathematics');

        $crawler = $this->client->request('GET', '/teacher');
        $this->client->click($crawler->selectLink('My subjects')->link());

        self::assertResponseIsSuccessful();
        $crawler = $this->client->getCrawler();
        self::assertSame(['Grade 7'], $crawler->filter('.subject-group h2')->each(static fn ($h) => $h->text()));
        self::assertCount(0, $crawler->filter('.deped-actions button'), 'Grade 7 already has subjects');
        self::assertSelectorNotExists('.sidebar__link.is-active:contains("Subjects")');
    }

    public function testDepEdButtonOnlyForAdvisoryGrade(): void
    {
        $this->advise(GradeLevel::Grade4, 'Rosal');

        $crawler = $this->client->request('GET', '/teacher/subjects');
        self::assertSame(['Add DepEd subjects for Grade 4'], $crawler->filter('.deped-actions button')->each(static fn ($b) => $b->text()));

        $this->client->submit($crawler->selectButton('Add DepEd subjects for Grade 4')->form());
        self::assertResponseRedirects('/teacher/subjects');
        self::assertNotNull($this->find('MAPEH4'));

        $this->client->request('POST', '/teacher/subjects/deped', ['grade' => '7', '_token' => 'x']);
        self::assertResponseRedirects();
        self::assertNull($this->find('MATH7'));
    }

    public function testTeacherCreatesSubjectForOwnGrade(): void
    {
        $this->advise(GradeLevel::Grade7, 'Narra');

        $crawler = $this->client->request('GET', '/teacher/subjects/new');
        self::assertSame(['7'], $crawler->filter('#subject_form_gradeLevel option')->each(static fn ($o) => $o->attr('value')));
        $this->client->submitForm('Create subject', [
            'subject_form[code]' => 'JOUR7',
            'subject_form[name]' => 'Journalism',
            'subject_form[meetingsPerWeek]' => '4',
        ]);

        self::assertResponseRedirects('/teacher/subjects');
        self::assertSame(GradeLevel::Grade7, $this->find('JOUR7')->getGradeLevel());
        self::assertContains('Created subject JOUR7 – Journalism', $this->activityActions());
    }

    public function testTeacherCannotTouchOtherGrades(): void
    {
        $this->advise(GradeLevel::Grade7, 'Narra');
        $grade8 = $this->subject(GradeLevel::Grade8, 'MATH8', 'Mathematics');

        $this->client->request('GET', '/teacher/subjects/'.$grade8->getId().'/edit');
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', '/teacher/subjects/new');
        $form = $crawler->selectButton('Create subject')->form()->disableValidation();
        $this->client->submit($form, ['subject_form[gradeLevel]' => '8', 'subject_form[code]' => 'SCI8', 'subject_form[name]' => 'Science', 'subject_form[meetingsPerWeek]' => '4']);
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->find('SCI8'));
    }

    public function testBringBackOnlyCopiesAdvisoryGrade(): void
    {
        $this->advise(GradeLevel::Grade7, 'Narra');
        $last = $this->schoolYear(2025, current: false);
        $this->subject(GradeLevel::Grade7, 'JOUR7', 'Journalism', $last);
        $this->subject(GradeLevel::Grade8, 'JOUR8', 'Journalism', $last);

        $crawler = $this->client->request('GET', '/teacher/subjects');
        $this->client->submit($crawler->selectButton('Bring back subjects from S.Y. 2025-2026')->form());

        self::assertResponseRedirects('/teacher/subjects');
        self::assertNotNull($this->find('JOUR7'));
        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(Subject::class)->count(['code' => 'JOUR8', 'schoolYear' => $this->sy->getId()]));
    }

    public function testShsDefaultsToSectionTrackAndStrand(): void
    {
        $this->advise(GradeLevel::Grade11, 'STEM 1', ShsTrack::Academic, 'STEM');

        $crawler = $this->client->request('GET', '/teacher/subjects/new');

        self::assertSame('academic', $crawler->filter('#subject_form_track option[selected]')->attr('value'));
        self::assertSame('STEM', $crawler->filter('#subject_form_strand')->attr('value'));
    }

    public function testWithoutAdvisoryShowsCreateSectionFirst(): void
    {
        $crawler = $this->client->request('GET', '/teacher/subjects');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Create your section first.');
        self::assertCount(1, $crawler->filter('main a[href="/teacher/section"]'));

        $this->client->request('GET', '/teacher/subjects/new');
        self::assertResponseRedirects('/teacher/subjects');
    }

    public function testBasicPlanTeacherForbidden(): void
    {
        $basic = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $ben = $this->createUser('ben', User::ROLE_TEACHER, 'Ben Cruz');
        $this->assign($basic, $this->sy, $ben, User::ROLE_TEACHER);
        $this->client->loginUser($ben);

        $crawler = $this->client->request('GET', '/teacher');
        self::assertCount(0, $crawler->filter('a:contains("My subjects")'));
        foreach (['GET /teacher/subjects', 'GET /teacher/subjects/new', 'POST /teacher/subjects/deped', 'POST /teacher/subjects/bring-back'] as $call) {
            [$method, $url] = explode(' ', $call);
            $this->client->request($method, $url);
            self::assertResponseStatusCodeSame(403, $call);
        }
    }
}
