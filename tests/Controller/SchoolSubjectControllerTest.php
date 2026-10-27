<?php

namespace App\Tests\Controller;

use App\Entity\GradeLevel;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Subject;
use App\Entity\User;
use App\Service\SubjectManager;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SchoolSubjectControllerTest extends AdminTestCase
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
        return '/schools/'.$school->getId().'/subjects'.$suffix;
    }

    private function subject(GradeLevel $grade, string $code, string $name, ?Subject $parent = null, ?School $school = null, ?SchoolYear $sy = null): Subject
    {
        $subject = (new Subject($school ?? $this->school, $sy ?? $this->sy))->setGradeLevel($grade)->setCode($code)->setName($name)->setParent($parent)->setMeetingsPerWeek(5);
        $this->em->persist($subject);
        $this->em->flush();

        return $subject;
    }

    private function addDepEd(GradeLevel $grade): void
    {
        static::getContainer()->get(SubjectManager::class)->addDepEdSet($this->school, $this->sy, $grade);
    }

    private function find(string $code): ?Subject
    {
        $this->em->clear();

        return $this->em->getRepository(Subject::class)->findOneBy(['code' => $code]);
    }

    public function testListGroupedWithComponentsUnderParent(): void
    {
        $this->addDepEd(GradeLevel::Grade7);

        $crawler = $this->client->request('GET', $this->url($this->school));

        self::assertResponseIsSuccessful();
        self::assertSame(['Grade 7'], $crawler->filter('.subject-group h2')->each(static fn ($h) => $h->text()));
        $rows = $crawler->filter('.subject-group tbody tr')->each(static fn ($tr) => [$tr->filter('code')->text(), $tr->attr('class')]);
        self::assertSame(['FIL7', 'ENG7', 'MATH7', 'SCI7', 'AP7', 'VE7', 'TLE7', 'MAPEH7', 'MA7', 'PEH7'], array_column($rows, 0));
        self::assertStringContainsString('subject-row--component', (string) $rows[8][1]);
        self::assertStringNotContainsString('subject-row--component', (string) $rows[7][1]);
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Subjects');

        $this->client->request('GET', '/principal/subjects');
        self::assertResponseRedirects($this->url($this->school));
    }

    public function testCreateSubject(): void
    {
        $this->client->request('GET', $this->url($this->school));
        $this->client->clickLink('+ New subject');
        $this->client->submitForm('Create subject', [
            'subject_form[gradeLevel]' => '8',
            'subject_form[code]' => 'sci8',
            'subject_form[name]' => 'Science',
            'subject_form[meetingsPerWeek]' => '4',
        ]);

        self::assertResponseRedirects($this->url($this->school));
        $subject = $this->find('SCI8');
        self::assertSame([GradeLevel::Grade8, 'Science', 1], [$subject->getGradeLevel(), $subject->getName(), $subject->getPosition()]);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'Created SCI8 – Science.');
        self::assertContains('Created subject SCI8 – Science', $this->activityActions());
    }

    public function testFormSavesMeetingsPerWeek(): void
    {
        $this->client->request('GET', $this->url($this->school, '/new'));
        $this->client->submitForm('Create subject', [
            'subject_form[gradeLevel]' => '7',
            'subject_form[code]' => 'SCI7',
            'subject_form[name]' => 'Science',
            'subject_form[meetingsPerWeek]' => '4',
        ]);

        self::assertResponseRedirects($this->url($this->school));
        self::assertSame(4, $this->find('SCI7')->getMeetingsPerWeek());
    }

    public function testFormRequiresMeetingsPerWeek(): void
    {
        $this->client->request('GET', $this->url($this->school, '/new'));
        $this->client->submitForm('Create subject', [
            'subject_form[gradeLevel]' => '7',
            'subject_form[code]' => 'SCI7',
            'subject_form[name]' => 'Science',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'Set how many times a week this subject meets.');
        self::assertNull($this->find('SCI7'));
    }

    public function testNewFormPreselectsGradeAndNextPosition(): void
    {
        $this->subject(GradeLevel::Grade9, 'MATH9', 'Mathematics');

        $crawler = $this->client->request('GET', $this->url($this->school, '/new?grade=9'));

        self::assertSame('9', $crawler->filter('#subject_form_gradeLevel option[selected]')->attr('value'));
        self::assertSame('2', $crawler->filter('#subject_form_position')->attr('value'));
    }

    public function testGradeChoicesFollowSchoolType(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->school, '/new'));

        self::assertSame(['7', '8', '9', '10'], $crawler->filter('#subject_form_gradeLevel option[value!=""]')->each(static fn ($o) => $o->attr('value')));
    }

    public function testCreateComponent(): void
    {
        $mapeh = $this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH');

        $this->client->request('GET', $this->url($this->school, '/new'));
        $this->client->submitForm('Create subject', [
            'subject_form[gradeLevel]' => '7',
            'subject_form[code]' => 'MA7',
            'subject_form[name]' => 'Music and Arts',
            'subject_form[meetingsPerWeek]' => '4',
            'subject_form[parent]' => (string) $mapeh->getId(),
        ]);

        self::assertResponseRedirects($this->url($this->school));
        self::assertSame('MA7 – Music and Arts (MAPEH)', $this->find('MA7')->getLabel());
    }

    public function testTamperedParentRejected(): void
    {
        $other = $this->school('303457', 'Other NHS');
        $foreign = $this->subject(GradeLevel::Grade7, 'MAPEH7', 'MAPEH', school: $other);
        $grade8 = $this->subject(GradeLevel::Grade8, 'MAPEH8', 'MAPEH');

        foreach ([$foreign, $grade8] as $parent) {
            $crawler = $this->client->request('GET', $this->url($this->school, '/new'));
            $form = $crawler->selectButton('Create subject')->form()->disableValidation();
            $this->client->submit($form, [
                'subject_form[gradeLevel]' => '7',
                'subject_form[code]' => 'MA7',
                'subject_form[name]' => 'Music and Arts',
                'subject_form[meetingsPerWeek]' => '4',
                'subject_form[parent]' => (string) $parent->getId(),
            ]);

            self::assertResponseStatusCodeSame(422);
        }
        self::assertSelectorTextContains('.form-card', 'A component must be in the same grade level as its subject.');
        self::assertNull($this->find('MA7'));
    }

    public function testDuplicateCodeAndNameShowErrors(): void
    {
        $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics');

        $this->client->request('GET', $this->url($this->school, '/new'));
        $this->client->submitForm('Create subject', [
            'subject_form[gradeLevel]' => '7',
            'subject_form[code]' => 'math7',
            'subject_form[name]' => 'Mathematics',
            'subject_form[meetingsPerWeek]' => '4',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'The code MATH7 is already used this school year.');
        self::assertSelectorTextContains('.form-card', 'Grade 7 already has a subject named Mathematics.');
    }

    public function testEditSubject(): void
    {
        $math = $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics');

        $this->client->request('GET', $this->url($this->school, '/'.$math->getId().'/edit'));
        $this->client->submitForm('Save subject', ['subject_form[name]' => 'Math']);

        self::assertResponseRedirects($this->url($this->school));
        self::assertSame('Math', $this->find('MATH7')->getName());
        self::assertContains('Updated subject MATH7 – Math', $this->activityActions());
    }

    public function testGradeOfSubjectWithComponentsCannotChange(): void
    {
        $this->addDepEd(GradeLevel::Grade7);
        $mapeh = $this->find('MAPEH7');

        $this->client->request('GET', $this->url($this->school, '/'.$mapeh->getId().'/edit'));
        $this->client->submitForm('Save subject', ['subject_form[gradeLevel]' => '8']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'Move or delete the components of MAPEH7 before changing its grade level.');
        self::assertSame(GradeLevel::Grade7, $this->find('MAPEH7')->getGradeLevel());

        // Other changes to a subject with components still save
        $this->client->request('GET', $this->url($this->school, '/'.$mapeh->getId().'/edit'));
        $this->client->submitForm('Save subject', ['subject_form[name]' => 'Music, Arts, PE and Health']);
        self::assertResponseRedirects($this->url($this->school));
    }

    public function testDeleteParentWithComponents(): void
    {
        $this->addDepEd(GradeLevel::Grade7);
        $mapeh = $this->find('MAPEH7');

        $crawler = $this->client->request('GET', $this->url($this->school));
        $form = $crawler->filter('tr:contains("MAPEH7")')->first()->filter('form');
        self::assertSame('Delete MAPEH7 – MAPEH? This also deletes its 2 components.', $form->attr('data-turbo-confirm'));
        $this->client->submit($form->selectButton('Delete')->form());

        self::assertResponseRedirects($this->url($this->school));
        self::assertNull($this->find('MAPEH7'));
        self::assertNull($this->find('MA7'));
        self::assertNull($this->find('PEH7'));
        self::assertNotNull($this->find('MATH7'));
        self::assertNotSame(null, $mapeh);
    }

    public function testDepEdButtonAddsSetAndHides(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->school));
        self::assertSame(['Add DepEd subjects for Grade 7', 'Add DepEd subjects for Grade 8', 'Add DepEd subjects for Grade 9', 'Add DepEd subjects for Grade 10'], $crawler->filter('.deped-actions button')->each(static fn ($b) => $b->text()));

        $this->client->submit($crawler->selectButton('Add DepEd subjects for Grade 7')->form());

        self::assertResponseRedirects($this->url($this->school));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'Added 10 DepEd subjects for Grade 7.');
        self::assertCount(0, $crawler->filter('button:contains("Add DepEd subjects for Grade 7")'));
        self::assertCount(1, $crawler->filter('button:contains("Add DepEd subjects for Grade 8")'));
        self::assertNotNull($this->find('MAPEH7'));
    }

    public function testBringBackButton(): void
    {
        $last = $this->schoolYear(2025, current: false);
        $crawler = $this->client->request('GET', $this->url($this->school));
        self::assertCount(0, $crawler->filter('button:contains("Bring back subjects")'));

        $this->subject(GradeLevel::Grade7, 'JOUR7', 'Journalism', sy: $last);
        $crawler = $this->client->request('GET', $this->url($this->school));
        $this->client->submit($crawler->selectButton('Bring back subjects from S.Y. 2025-2026')->form());

        self::assertResponseRedirects($this->url($this->school));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert', 'Brought back 1 subjects from S.Y. 2025-2026.');
        self::assertCount(0, $crawler->filter('button:contains("Bring back subjects")'));
    }

    public function testOtherSchoolAndTeacherAreForbidden(): void
    {
        $other = $this->school('303457', 'Other NHS');
        $theirs = $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics', school: $other);

        foreach (['', '/new', '/'.$theirs->getId().'/edit'] as $suffix) {
            $this->client->request('GET', $this->url($other, $suffix));
            self::assertResponseStatusCodeSame(403);
        }

        $this->client->loginUser($this->createUser('ana', User::ROLE_TEACHER));
        $this->client->request('GET', $this->url($this->school));
        self::assertResponseStatusCodeSame(403);
    }

    public function testSubjectOfAnotherSchoolIsNotFoundHere(): void
    {
        $theirs = $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics', school: $this->school('303457', 'Other NHS'));

        $this->client->request('GET', $this->url($this->school, '/'.$theirs->getId().'/edit'));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminManagesAnySchoolAndHasLink(): void
    {
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/admin/schools');
        $this->client->click($crawler->filter('tr:contains("Pagsabungan NHS")')->selectLink('Subjects')->link());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', $this->url($this->school, '/new'));
        $this->client->submitForm('Create subject', [
            'subject_form[gradeLevel]' => '10',
            'subject_form[code]' => 'RES10',
            'subject_form[name]' => 'Research',
            'subject_form[meetingsPerWeek]' => '4',
        ]);
        self::assertResponseRedirects($this->url($this->school));
        self::assertNotNull($this->find('RES10'));
    }

    public function testExpiredPlanIsViewOnly(): void
    {
        $math = $this->subject(GradeLevel::Grade7, 'MATH7', 'Mathematics');
        $this->subject(GradeLevel::Grade8, 'MATH8', 'Mathematics', sy: $this->schoolYear(2025, current: false));
        $this->em->getRepository(School::class)->find($this->school->getId())->setPlanValidUntil(new \DateTimeImmutable('2026-10-15'));
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->school));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.plan-notice', 'expired on Oct 15, 2026');
        self::assertSelectorTextContains('.subject-group', 'MATH7');
        self::assertCount(0, $crawler->filter('a:contains("New subject"), button:contains("Add DepEd"), button:contains("Bring back"), button:contains("Delete")'));

        $this->client->request('GET', $this->url($this->school, '/new'));
        self::assertResponseRedirects($this->url($this->school));
        $this->client->request('GET', $this->url($this->school, '/'.$math->getId().'/edit'));
        self::assertResponseRedirects($this->url($this->school));
    }
}
