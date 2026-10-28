<?php

namespace App\Tests\Controller;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\Learner;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\TransferRequest;
use App\Entity\TransferStatus;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class SchoolLearnerControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private User $maria;
    private Section $sampaguita;
    private Section $rosal;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-10-28');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303456', 'Pagsabungan NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $this->maria = $this->createUser('maria', User::ROLE_PRINCIPAL, 'Maria Santos');
        $this->assign($this->school, $this->sy, $this->maria);
        $this->sampaguita = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Sampaguita');
        $this->rosal = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'Rosal');
        $this->client->loginUser($this->maria);
    }

    private function url(School $school, string $suffix = ''): string
    {
        return '/schools/'.$school->getId().'/learners'.$suffix;
    }

    private function classUrl(School $school, Section $section): string
    {
        return '/schools/'.$school->getId().'/sections/'.$section->getId().'/learners';
    }

    private function enrolled(string $lrn, string $last, string $first, ?Section $section = null, SexAtBirth $sex = SexAtBirth::Female): Learner
    {
        $learner = $this->learner($lrn, $last, $first)->setSex($sex);
        $section ??= $this->sampaguita;
        $this->enroll($learner, $section->getSchoolYear(), $section, $this->maria);

        return $learner;
    }

    private function findLearner(string $lrn): ?Learner
    {
        $this->em->clear();

        return $this->em->getRepository(Learner::class)->findOneBy(['lrn' => $lrn]);
    }

    private function currentEnrollment(Learner $learner): ?Enrollment
    {
        $this->em->clear();

        return $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $learner->getId(), 'schoolYear' => $this->sy->getId()]);
    }

    /** @return array<string, string> */
    private function newLearnerFields(Section $section): array
    {
        return [
            'enroll_section_form[learner][lastName]' => 'Dela Cruz',
            'enroll_section_form[learner][firstName]' => 'Juan',
            'enroll_section_form[learner][middleName]' => 'Santos',
            'enroll_section_form[learner][extension]' => 'Jr.',
            'enroll_section_form[learner][sex]' => 'male',
            'enroll_section_form[learner][birthDate]' => '2013-02-14',
            'enroll_section_form[section]' => (string) $section->getId(),
        ];
    }

    public function testEnrollNewLearner(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->school));
        $this->client->click($crawler->selectLink('Enroll')->link());
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Next', ['lrn' => '123456789012'], 'GET');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card', '123456789012');

        $this->client->submitForm('Enroll', $this->newLearnerFields($this->rosal));

        self::assertResponseRedirects($this->classUrl($this->school, $this->rosal));
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.credentials', 'Dela Cruz, Juan Santos Jr.');
        self::assertSame('123456789012', $crawler->filter('.credentials dd code')->first()->text());
        self::assertSelectorTextContains('.table', 'Dela Cruz, Juan Santos Jr.');

        $learner = $this->findLearner('123456789012');
        self::assertSame(User::ROLE_STUDENT, $learner->getUser()->getRole());
        self::assertSame('123456789012', $learner->getUser()->getUsername());
        self::assertTrue($learner->getUser()->mustChangePassword());
        self::assertSame($this->rosal->getId(), $this->currentEnrollment($learner)->getSection()->getId());
        self::assertContains('Enrolled 123456789012 in Grade 7 – Rosal', $this->activityActions());
    }

    public function testEnrollRejectsBadLrnAndShowsFormErrors(): void
    {
        $this->client->request('GET', $this->url($this->school, '/enroll'));
        $this->client->submitForm('Next', ['lrn' => '12345'], 'GET');
        self::assertSelectorTextContains('.form-card', 'The LRN must be exactly 12 digits.');
        self::assertSelectorNotExists('button:contains("Enroll")');

        $this->client->request('GET', $this->url($this->school, '/enroll?lrn=123456789012'));
        $fields = $this->newLearnerFields($this->rosal);
        $fields['enroll_section_form[learner][lastName]'] = '';
        $this->client->submitForm('Enroll', $fields);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'Enter the last name.');
        self::assertNull($this->findLearner('123456789012'));
    }

    public function testEnrollReturningLearner(): void
    {
        $last = $this->schoolYear(2025, current: false);
        $old = $this->newSection($this->school, $last, GradeLevel::Grade7, 'Old');
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana', $old);

        $crawler = $this->client->request('GET', $this->url($this->school, '/enroll?lrn=111111111111'));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.card', 'Abad, Ana');
        self::assertCount(0, $crawler->filter('input[name="enroll_section_form[learner][lastName]"]'));
        self::assertCount(0, $crawler->selectLink('Edit details'), 'R4: only admins edit a learner not enrolled this year');

        $this->client->submitForm('Enroll', ['enroll_section_form[section]' => (string) $this->rosal->getId()]);

        self::assertResponseRedirects($this->classUrl($this->school, $this->rosal));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Enrolled Abad, Ana in Grade 7 – Rosal.');
        self::assertSame($this->rosal->getId(), $this->currentEnrollment($learner)->getSection()->getId());
    }

    public function testAlreadyEnrolledHereShowsLink(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');

        $crawler = $this->client->request('GET', $this->url($this->school, '/enroll?lrn=111111111111'));

        self::assertSelectorTextContains('.card', 'Abad, Ana is already enrolled in Grade 7 – Sampaguita.');
        self::assertSame($this->url($this->school, '/'.$learner->getId()), $crawler->selectLink('Open learner')->attr('href'));
        self::assertSelectorNotExists('button:contains("Enroll")');
    }

    public function testEnrolledElsewhereOffersTransfer(): void
    {
        $other = $this->school('303457', 'Other NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana', $this->newSection($other, $this->sy, GradeLevel::Grade7, 'Mabini'));

        $crawler = $this->client->request('GET', $this->url($this->school, '/enroll?lrn=111111111111'));

        self::assertSelectorTextContains('.card', 'Abad, Ana is enrolled at Other NHS.');
        self::assertCount(1, $crawler->selectButton('Request transfer'));
        self::assertSelectorNotExists('button:contains("Enroll")');

        $this->client->submitForm('Request transfer', ['enroll_section_form[section]' => (string) $this->rosal->getId()]);
        self::assertResponseRedirects($this->url($this->school));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Transfer request sent to Other NHS.');

        $this->em->clear();
        $request = $this->em->getRepository(TransferRequest::class)->findOneBy(['learner' => $learner->getId()]);
        self::assertSame(TransferStatus::Pending, $request->getStatus());
        self::assertSame($this->rosal->getId(), $request->getToSection()->getId());
    }

    public function testClassListOrderAndCount(): void
    {
        $this->enrolled('111111111111', 'Abad', 'Ana');
        $this->enrolled('222222222222', 'Zamora', 'Ben', sex: SexAtBirth::Male);
        $this->enrolled('333333333333', 'Cruz', 'Carl', sex: SexAtBirth::Male);
        $this->enrolled('444444444444', 'Diaz', 'Dina', $this->rosal);

        $crawler = $this->client->request('GET', $this->classUrl($this->school, $this->sampaguita));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Grade 7 – Sampaguita');
        self::assertSelectorTextContains('.page-head', '3 learners');
        $names = $crawler->filter('.table tbody tr td:nth-child(3)')->each(static fn ($td) => $td->text());
        self::assertSame(['Cruz, Carl', 'Zamora, Ben', 'Abad, Ana'], $names);
        self::assertSame(['1', '2', '3'], $crawler->filter('.table tbody tr td:first-child')->each(static fn ($td) => $td->text()));
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Learners');

        $this->enrolled('555555555555', 'Eco', 'Eli', $this->rosal);
        $this->client->request('GET', $this->classUrl($this->school, $this->rosal));
        self::assertSelectorTextContains('.page-head', '2 learners');
    }

    public function testClassListShowsSectionLimitWithoutPrincipal(): void
    {
        $this->school->setPlan($this->limitedPlan('Solo', 40, includesPrincipal: false));
        $this->em->flush();
        $this->enrolled('111111111111', 'Abad', 'Ana');

        $this->client->loginUser($this->admin);
        $this->client->request('GET', $this->classUrl($this->school, $this->sampaguita));

        self::assertSelectorTextContains('.page-head', '1 learner of 40');
    }

    public function testSearchByLrnAndName(): void
    {
        $this->enrolled('111111111111', 'Abad', 'Ana');
        $this->enrolled('222222222222', 'Zamora', 'Ben', $this->rosal);

        $crawler = $this->client->request('GET', $this->url($this->school, '?q=2222'));
        self::assertSame(['Zamora, Ben'], $crawler->filter('.table tbody tr td:nth-child(2)')->each(static fn ($td) => $td->text()));

        $crawler = $this->client->request('GET', $this->url($this->school, '?q=abad'));
        self::assertSame(['Abad, Ana'], $crawler->filter('.table tbody tr td:nth-child(2)')->each(static fn ($td) => $td->text()));

        $crawler = $this->client->request('GET', $this->url($this->school));
        self::assertCount(2, $crawler->filter('.table tbody tr'));
        self::assertSelectorTextContains('.page-head', '2 learners');
    }

    public function testFilterBySection(): void
    {
        $this->enrolled('111111111111', 'Abad', 'Ana');
        $this->enrolled('222222222222', 'Zamora', 'Ben', $this->rosal);

        $crawler = $this->client->request('GET', $this->url($this->school));
        $crawler = $this->client->submit($crawler->selectButton('Search')->form(['section' => (string) $this->rosal->getId()]));
        self::assertSame(['Zamora, Ben'], $crawler->filter('.table tbody tr td:nth-child(2)')->each(static fn ($td) => $td->text()));

        $crawler = $this->client->request('GET', $this->url($this->school, '?q=&section='));
        self::assertCount(2, $crawler->filter('.table tbody tr'));
    }

    public function testEditAndChangeLrn(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');

        $crawler = $this->client->request('GET', $this->url($this->school, '/'.$learner->getId()));
        $this->client->click($crawler->selectLink('Edit details')->link());
        self::assertResponseIsSuccessful();

        $this->client->submitForm('Save', ['learner_form[lrn]' => '12', 'learner_form[firstName]' => 'Anna']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'The LRN must be exactly 12 digits.');
        self::assertSame('Ana', $this->findLearner('111111111111')->getFirstName());

        $this->client->submitForm('Save', ['learner_form[lrn]' => '999999999999', 'learner_form[firstName]' => 'Anna']);

        self::assertResponseRedirects($this->url($this->school, '/'.$learner->getId()));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Saved Abad, Anna.');
        $learner = $this->findLearner('999999999999');
        self::assertSame('Anna', $learner->getFirstName());
        self::assertSame('999999999999', $learner->getUser()->getUsername());
        self::assertSame('Abad, Anna', $learner->getUser()->getFullName());
        $actions = $this->activityActions();
        self::assertContains('Changed LRN 111111111111 to 999999999999', $actions);
        self::assertContains('Updated learner 999999999999', $actions);
    }

    public function testEditRefusesLrnOfAnotherLearner(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $this->enrolled('222222222222', 'Zamora', 'Ben');

        $this->client->request('GET', $this->url($this->school, '/'.$learner->getId().'/edit'));
        $this->client->submitForm('Save', ['learner_form[lrn]' => '222222222222']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'LRN 222222222222 belongs to Zamora, Ben.');
        self::assertSame('111111111111', $this->findLearner('111111111111')->getUser()->getUsername());
    }

    public function testMoveUnenrollResetPassword(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $show = $this->url($this->school, '/'.$learner->getId());

        $crawler = $this->client->request('GET', $show);
        $this->client->submit($crawler->selectButton('Move')->form(['section' => (string) $this->rosal->getId()]));
        self::assertResponseRedirects($show);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Moved Abad, Ana to Grade 7 – Rosal.');
        self::assertSame($this->rosal->getId(), $this->currentEnrollment($learner)->getSection()->getId());

        $this->client->submit($crawler->selectButton('Reset password')->form());
        self::assertResponseRedirects($show);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.credentials', 'Password reset');
        self::assertSame('111111111111', $crawler->filter('.credentials dd code')->first()->text());
        self::assertTrue($this->findLearner('111111111111')->getUser()->mustChangePassword());

        $this->client->submit($crawler->selectButton('Unenroll')->form());
        self::assertResponseRedirects($this->url($this->school));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Unenrolled Abad, Ana from Grade 7 – Rosal.');
        self::assertNull($this->currentEnrollment($learner));
        self::assertNotNull($this->findLearner('111111111111'));

        $actions = $this->activityActions();
        self::assertContains('Moved 111111111111 from Grade 7 – Sampaguita to Grade 7 – Rosal', $actions);
        self::assertContains('Reset password of learner 111111111111', $actions);
        self::assertContains('Unenrolled 111111111111 from Grade 7 – Rosal', $actions);
    }

    public function testShowListsHistory(): void
    {
        $last = $this->schoolYear(2025, current: false);
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana', $this->newSection($this->school, $last, GradeLevel::Grade7, 'Old'));
        $this->enroll($learner, $this->sy, $this->rosal, $this->maria);

        $crawler = $this->client->request('GET', $this->url($this->school, '/'.$learner->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame(['2026-2027', '2025-2026'], $crawler->filter('.history tbody tr td:first-child')->each(static fn ($td) => $td->text()));
        self::assertSelectorTextContains('.history', 'Grade 7 – Old');
    }

    public function testSectionsPageShowsEnrolledColumn(): void
    {
        $this->enrolled('111111111111', 'Abad', 'Ana');
        $this->enrolled('222222222222', 'Zamora', 'Ben');

        $crawler = $this->client->request('GET', '/schools/'.$this->school->getId().'/sections');

        self::assertSelectorTextContains('.section-group thead', 'Enrolled');
        $link = $crawler->filter('tr:contains("Sampaguita")')->selectLink('2');
        self::assertSame($this->classUrl($this->school, $this->sampaguita), $link->attr('href'));
        self::assertSame($this->classUrl($this->school, $this->rosal), $crawler->filter('tr:contains("Rosal")')->selectLink('0')->attr('href'));
    }

    public function testPrincipalNavHasLearners(): void
    {
        $crawler = $this->client->request('GET', '/principal');
        self::assertSame('/principal/learners', $crawler->filter('.sidebar__nav')->selectLink('Learners')->attr('href'));

        $this->client->request('GET', '/principal/learners');
        self::assertResponseRedirects($this->url($this->school));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Learners');
    }

    public function testOtherSchoolIs403(): void
    {
        $other = $this->school('303457', 'Other NHS');
        $section = $this->newSection($other, $this->sy, GradeLevel::Grade7, 'Mabini');
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana', $section);

        foreach (['', '/enroll', '/'.$learner->getId(), '/'.$learner->getId().'/edit'] as $suffix) {
            $this->client->request('GET', $this->url($other, $suffix));
            self::assertResponseStatusCodeSame(403, $suffix);
        }
        $this->client->request('GET', $this->classUrl($other, $section));
        self::assertResponseStatusCodeSame(403);

        $this->client->loginUser($this->createUser('ana', User::ROLE_TEACHER));
        $this->client->request('GET', $this->url($this->school));
        self::assertResponseStatusCodeSame(403);
    }

    public function testLearnerOfOtherSchoolIs404(): void
    {
        $other = $this->school('303457', 'Other NHS');
        $theirs = $this->enrolled('111111111111', 'Abad', 'Ana', $this->newSection($other, $this->sy, GradeLevel::Grade7, 'Mabini'));
        $nowhere = $this->learner('222222222222', 'Zamora', 'Ben');

        foreach ([$theirs, $nowhere] as $learner) {
            foreach (['', '/edit'] as $suffix) {
                $this->client->request('GET', $this->url($this->school, '/'.$learner->getId().$suffix));
                self::assertResponseStatusCodeSame(404);
            }
        }
    }

    public function testSectionOfOtherSchoolIs404(): void
    {
        $other = $this->school('303457', 'Other NHS');
        $theirSection = $this->newSection($other, $this->sy, GradeLevel::Grade7, 'Mabini');
        $lastYear = $this->newSection($this->school, $this->schoolYear(2025, current: false), GradeLevel::Grade7, 'Old');
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');

        foreach ([$theirSection, $lastYear] as $section) {
            $this->client->request('GET', $this->classUrl($this->school, $section));
            self::assertResponseStatusCodeSame(404);
            $this->client->request('GET', $this->url($this->school, '?section='.$section->getId()));
            self::assertResponseStatusCodeSame(404);
        }

        $crawler = $this->client->request('GET', $this->url($this->school, '/'.$learner->getId()));
        $form = $crawler->selectButton('Move')->form();
        $form->disableValidation()->setValues(['section' => (string) $theirSection->getId()]);
        $this->client->submit($form);
        self::assertResponseStatusCodeSame(404);
        self::assertSame($this->sampaguita->getId(), $this->currentEnrollment($learner)->getSection()->getId());
    }

    public function testLimitMessageShown(): void
    {
        $this->school->setPlan($this->limitedPlan('Tiny', 1));
        $this->em->flush();
        $this->enrolled('111111111111', 'Abad', 'Ana');

        $this->client->request('GET', $this->url($this->school, '/enroll?lrn=123456789012'));
        $this->client->submitForm('Enroll', $this->newLearnerFields($this->rosal));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'The Tiny plan allows 1 student(s) (1 used). Contact the administrator to upgrade.');
        self::assertNull($this->findLearner('123456789012'));
        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->findOneBy(['username' => '123456789012']));
    }

    public function testAdminEnrollsIntoAnySchool(): void
    {
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/admin/schools');
        $this->client->click($crawler->filter('tr:contains("Pagsabungan NHS")')->selectLink('Learners')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('.sidebar__brand'); // admin layout
        $this->client->clickLink('Enroll');
        $this->client->submitForm('Next', ['lrn' => '123456789012'], 'GET');
        $this->client->submitForm('Enroll', $this->newLearnerFields($this->sampaguita));

        self::assertResponseRedirects($this->classUrl($this->school, $this->sampaguita));
        $this->client->followRedirect();
        self::assertSelectorTextContains('.credentials', '123456789012');
        self::assertSame($this->admin->getId(), $this->currentEnrollment($this->findLearner('123456789012'))->getEnrolledBy()->getId());
    }

    public function testAdminSeesEditDetailsAndHistoryOfAnyLearner(): void
    {
        $other = $this->school('303457', 'Other NHS');
        $last = $this->schoolYear(2025, current: false);
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana', $this->newSection($other, $last, GradeLevel::Grade7, 'Old'));
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', $this->url($this->school, '/enroll?lrn=111111111111'));
        self::assertSame($this->url($this->school, '/'.$learner->getId().'/edit'), $crawler->selectLink('Edit details')->attr('href'));

        $crawler = $this->client->request('GET', $this->url($this->school, '/'.$learner->getId()));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.history', 'Other NHS');
        self::assertCount(0, $crawler->selectButton('Move'));
        self::assertCount(0, $crawler->selectButton('Unenroll'));
    }
}
