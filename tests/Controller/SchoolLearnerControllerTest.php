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
use Symfony\Component\Lock\LockFactory;

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

    public function testClassListMarksIncompleteLearners(): void
    {
        $this->enrolled('111111111111', 'Abad', 'Ana');
        $this->enrolled('222222222222', 'Cruz', 'Carl')->setAddressBarangay('Pagsabungan')->setAddressCity('Mandaue City')
            ->setAddressProvince('Cebu')->setMotherLastName('Molina')->setMotherFirstName('Angelle')->setContactNumber('09171234567');
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->classUrl($this->school, $this->sampaguita));

        $rows = $crawler->filter('.table tbody tr');
        self::assertStringContainsString('SF1 incomplete', $rows->eq(0)->text());
        self::assertStringNotContainsString('SF1 incomplete', $rows->eq(1)->text());
        $learner = $this->findLearner('111111111111');
        self::assertSame($this->url($this->school, '/'.$learner->getId().'/edit'), $rows->eq(0)->filter('a.badge')->attr('href'));
    }

    private function assertSf1Download(string $fileName): void
    {
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/vnd.ms-excel');
        self::assertStringContainsString('attachment', $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('filename="'.$fileName.'"', $this->client->getResponse()->headers->get('Content-Disposition'));
        // .xls files are OLE compound documents
        self::assertStringStartsWith("\xD0\xCF\x11\xE0", $this->client->getInternalResponse()->getContent());
    }

    public function testPrincipalDownloadsSf1(): void
    {
        $this->enrolled('111111111111', 'Abad', 'Ana');

        $crawler = $this->client->request('GET', $this->classUrl($this->school, $this->sampaguita));
        $this->client->click($crawler->selectLink('Download SF1')->link());

        $this->assertSf1Download('SF1_2026_Grade 7 - SAMPAGUITA.xls');
    }

    public function testAdminDownloadsSf1OfEmptySection(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('GET', '/schools/'.$this->school->getId().'/sections/'.$this->rosal->getId().'/sf1');

        $this->assertSf1Download('SF1_2026_Grade 7 - ROSAL.xls');
    }

    public function testSf1OfSectionWithSlashInNameDownloads(): void
    {
        $section = $this->newSection($this->school, $this->sy, GradeLevel::Grade7, 'St. Peter/Paul 100%');

        $this->client->request('GET', '/schools/'.$this->school->getId().'/sections/'.$section->getId().'/sf1');

        $this->assertSf1Download('SF1_2026_Grade 7 - ST. PETER-PAUL 100-.xls');
    }

    public function testRemarkDetailsWithoutCodeIs422(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');

        $this->client->request('GET', $this->url($this->school, '/'.$learner->getId().'/enrollment'));
        $this->client->submitForm('Save', ['enrollment_record_form[remarkDetails]' => 'Moved to Cebu']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'Choose a remark for these details.');
        self::assertNull($this->currentEnrollment($learner)->getRemarkDetails());
    }

    public function testSf1OfOtherSchoolIsRefused(): void
    {
        $other = $this->school('303457', 'Other NHS');
        $section = $this->newSection($other, $this->sy, GradeLevel::Grade7, 'Mabini');

        $this->client->request('GET', '/schools/'.$other->getId().'/sections/'.$section->getId().'/sf1');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/schools/'.$this->school->getId().'/sections/'.$section->getId().'/sf1');
        self::assertResponseStatusCodeSame(404);
    }

    public function testStudentCannotDownloadSf1(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $learner->getUser()->setMustChangePassword(false);
        $this->em->flush();
        $this->client->loginUser($learner->getUser());

        $this->client->request('GET', '/schools/'.$this->school->getId().'/sections/'.$this->sampaguita->getId().'/sf1');

        self::assertResponseStatusCodeSame(403);
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

    public function testIndexIsPagedByFifty(): void
    {
        for ($i = 1; $i <= 51; ++$i) {
            $this->enrolled(sprintf('1000000000%02d', $i), sprintf('Lastname%02d', $i), 'Ana');
        }

        $crawler = $this->client->request('GET', $this->url($this->school));
        self::assertCount(50, $crawler->filter('.table tbody tr'));
        self::assertSelectorTextContains('.page-head', '51 learners');
        self::assertSelectorNotExists('.pager a:contains("Previous")');
        $next = $crawler->filter('.pager a:contains("Next")');
        self::assertCount(1, $next);

        $crawler = $this->client->request('GET', $this->url($this->school, '?page=2'));
        self::assertSame(['Lastname51, Ana'], $crawler->filter('.table tbody tr td:nth-child(2)')->each(static fn ($td) => $td->text()));
        self::assertSelectorTextContains('.page-head', '51 learners');
        self::assertSelectorNotExists('.pager a:contains("Next")');
        self::assertCount(1, $crawler->filter('.pager a:contains("Previous")'));
    }

    public function testPagerKeepsSearchAndSection(): void
    {
        for ($i = 1; $i <= 51; ++$i) {
            $this->enrolled(sprintf('1000000000%02d', $i), sprintf('Lastname%02d', $i), 'Ana', $this->rosal);
        }

        $crawler = $this->client->request('GET', $this->url($this->school, '?q=Lastname&section='.$this->rosal->getId()));
        $href = $crawler->filter('.pager a:contains("Next")')->attr('href');

        self::assertStringContainsString('q=Lastname', $href);
        self::assertStringContainsString('section='.$this->rosal->getId(), $href);
        self::assertStringContainsString('page=2', $href);
    }

    public function testIndexQueryCountDoesNotGrowWithLearners(): void
    {
        for ($i = 1; $i <= 9; ++$i) {
            $this->enrolled('10000000000'.$i, 'Abad'.$i, 'Ana');
        }

        // the same page listing one learner, then all nine: lazy loading would add queries per learner.
        // The first request only drops the fixtures' queries from the profiler's log.
        $this->client->request('GET', $this->url($this->school));
        $this->client->enableProfiler();
        $this->client->request('GET', $this->url($this->school, '?q=Abad1'));
        $one = \count($this->client->getProfile()->getCollector('db')->getQueries()['default']);
        $this->client->enableProfiler();
        $crawler = $this->client->request('GET', $this->url($this->school));
        $all = \count($this->client->getProfile()->getCollector('db')->getQueries()['default']);

        self::assertCount(9, $crawler->filter('.table tbody tr'));
        self::assertSame($one, $all);
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

    /** @return array<string, string> */
    private function sf1Fields(): array
    {
        return [
            'learner_form[religion]' => 'Christianity',
            'learner_form[addressBarangay]' => 'Pagsabungan',
            'learner_form[motherLastName]' => 'Molina',
            'learner_form[motherFirstName]' => 'Angelle',
            'learner_form[contactNumber]' => '09171234567',
        ];
    }

    public function testPrincipalSavesSf1Fields(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');

        $this->client->request('GET', $this->url($this->school, '/'.$learner->getId().'/edit'));
        $this->client->submitForm('Save', $this->sf1Fields());

        self::assertResponseRedirects($this->url($this->school, '/'.$learner->getId()));
        $saved = $this->findLearner('111111111111');
        self::assertSame('Christianity', $saved->getReligion());
        self::assertSame('Pagsabungan', $saved->getAddressBarangay());
        self::assertSame('MOLINA, ANGELLE', $saved->getMotherSf1Name());
        self::assertSame('09171234567', $saved->getContactNumber());
        self::assertNull($saved->getIpGroup());

        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Pagsabungan');
        self::assertSelectorTextContains('main', '09171234567');
    }

    public function testAdminSavesSf1Fields(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $this->client->loginUser($this->admin);

        $this->client->request('GET', $this->url($this->school, '/'.$learner->getId().'/edit'));
        $this->client->submitForm('Save', $this->sf1Fields());

        self::assertResponseRedirects($this->url($this->school, '/'.$learner->getId()));
        self::assertSame('Christianity', $this->findLearner('111111111111')->getReligion());
    }

    public function testSf1FieldTooLongIs422(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');

        $this->client->request('GET', $this->url($this->school, '/'.$learner->getId().'/edit'));
        $this->client->submitForm('Save', ['learner_form[addressStreet]' => str_repeat('x', 101)]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->findLearner('111111111111')->getAddressStreet());
    }

    public function testPrincipalSetsModalityAndRemark(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $show = $this->url($this->school, '/'.$learner->getId());

        $crawler = $this->client->request('GET', $show);
        $this->client->click($crawler->selectLink('This school year')->link());
        self::assertResponseIsSuccessful();
        $this->client->submitForm('Save', [
            'enrollment_record_form[learningModality]' => 'modular_digital',
            'enrollment_record_form[remarkCode]' => 'late_enrollment',
            'enrollment_record_form[remarkDetails]' => 'Enrolled in July',
        ]);

        self::assertResponseRedirects($show);
        $this->client->followRedirect();
        self::assertSelectorTextContains('main', 'Modular (Digital)');
        self::assertSelectorTextContains('main', 'LE Enrolled in July');
        self::assertContains('Updated SF1 record of 111111111111', $this->activityActions());
    }

    public function testRemarkDetailsTooLongIs422(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');

        $this->client->request('GET', $this->url($this->school, '/'.$learner->getId().'/enrollment'));
        $this->client->submitForm('Save', ['enrollment_record_form[remarkDetails]' => str_repeat('x', 151)]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->currentEnrollment($learner)->getRemarkDetails());
    }

    public function testAutomaticTiHintShown(): void
    {
        $other = $this->school('303457', 'Mandaue NHS');
        $learner = $this->learner('111111111111', 'Abad', 'Ana');
        $enrollment = $this->enroll($learner, $this->sy, $this->newSection($other, $this->sy, GradeLevel::Grade7, 'Bonifacio'), $this->maria);
        $request = (new TransferRequest($enrollment, $this->sampaguita, $this->maria, new \DateTimeImmutable('2026-06-15')))
            ->decide(TransferStatus::Approved, $this->maria, new \DateTimeImmutable('2026-06-16'));
        $enrollment->moveTo($this->sampaguita);
        $this->em->persist($request);
        $this->em->flush();

        $this->client->request('GET', $this->url($this->school, '/'.$learner->getId().'/enrollment'));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Automatic: T/I Mandaue NHS 2026-06-16');
    }

    public function testEnrollmentRecordOfOtherSchoolIs403AndNotEnrolledIs404(): void
    {
        $other = $this->school('303457', 'Other NHS');
        $theirs = $this->enrolled('111111111111', 'Abad', 'Ana', $this->newSection($other, $this->sy, GradeLevel::Grade7, 'Mabini'));
        $this->client->request('GET', $this->url($other, '/'.$theirs->getId().'/enrollment'));
        self::assertResponseStatusCodeSame(403);

        $lastYear = $this->learner('222222222222', 'Zamora', 'Ben');
        $old = $this->schoolYear(2025, current: false);
        $this->enroll($lastYear, $old, $this->newSection($this->school, $old, GradeLevel::Grade7, 'Old'), $this->maria);
        $this->client->loginUser($this->admin);
        $this->client->request('GET', $this->url($this->school, '/'.$lastYear->getId().'/enrollment'));
        self::assertResponseStatusCodeSame(404);
    }

    public function testStudentCannotOpenEnrollmentRecord(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $learner->getUser()->setMustChangePassword(false);
        $this->em->flush();
        $this->client->loginUser($learner->getUser());

        $this->client->request('GET', $this->url($this->school, '/'.$learner->getId().'/enrollment'));

        self::assertResponseStatusCodeSame(403);
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

    /**
     * Learners the principal's school must not write to: one at another school, one enrolled nowhere.
     *
     * @return array{0: Learner, 1: Learner, 2: Section}
     */
    private function learnersNotHere(): array
    {
        $other = $this->school('303457', 'Other NHS', planValidUntil: new \DateTimeImmutable('2027-04-30'));
        $mabini = $this->newSection($other, $this->sy, GradeLevel::Grade7, 'Mabini');
        $theirs = $this->enrolled('111111111111', 'Abad', 'Ana', $mabini);
        $nowhere = $this->learner('222222222222', 'Zamora', 'Ben');

        return [$theirs, $nowhere, $mabini];
    }

    /** @param list<string> $actions */
    private function assertWritesAre404(array $learners, array $actions): void
    {
        $this->client->request('GET', $this->url($this->school));
        foreach ($learners as $learner) {
            $token = $this->csrfToken('learner-'.$learner->getId());
            foreach ($actions as $action) {
                $this->client->request('POST', $this->url($this->school, '/'.$learner->getId().'/'.$action), [
                    '_token' => $token,
                    'section' => (string) $this->rosal->getId(),
                ]);
                self::assertResponseStatusCodeSame(404, $learner->getLrn().' '.$action);
            }
        }
    }

    public function testPrincipalCannotWriteToLearnersNotEnrolledHere(): void
    {
        [$theirs, $nowhere, $mabini] = $this->learnersNotHere();
        $hashes = [$theirs->getUser()->getPassword(), $nowhere->getUser()->getPassword()];

        $this->assertWritesAre404([$theirs, $nowhere], ['move', 'unenroll', 'reset-password']);

        self::assertSame($mabini->getId(), $this->currentEnrollment($theirs)->getSection()->getId());
        self::assertNull($this->currentEnrollment($nowhere));
        self::assertSame($hashes, [$this->findLearner('111111111111')->getUser()->getPassword(), $this->findLearner('222222222222')->getUser()->getPassword()]);
        self::assertSame([], $this->activityActions());
    }

    public function testAdminMoveAndUnenrollNeedACurrentEnrollmentHere(): void
    {
        [$theirs, $nowhere, $mabini] = $this->learnersNotHere();
        $this->client->loginUser($this->admin);

        $this->assertWritesAre404([$theirs, $nowhere], ['move', 'unenroll']);

        self::assertSame($mabini->getId(), $this->currentEnrollment($theirs)->getSection()->getId());
        self::assertNull($this->currentEnrollment($nowhere));
        self::assertSame([], $this->activityActions());
    }

    public function testOnlyThePrincipalOfTheSchoolRequestsTransfers(): void
    {
        [$theirs] = $this->learnersNotHere();
        $pedro = $this->createUser('pedro', User::ROLE_PRINCIPAL, 'Pedro Cruz');
        $this->assign($this->school('303458', 'Third NHS'), $this->sy, $pedro);
        $teacher = $this->createUser('ana', User::ROLE_TEACHER);
        $this->assign($this->school, $this->sy, $teacher, User::ROLE_TEACHER);

        foreach ([$this->admin, $teacher, $pedro] as $user) {
            $this->client->loginUser($user);
            $this->client->request('POST', '/schools/'.$this->school->getId().'/transfers/request?lrn='.$theirs->getLrn(), [
                'enroll_section_form' => ['section' => (string) $this->rosal->getId()],
            ]);
            self::assertResponseStatusCodeSame(403, $user->getUsername());
        }

        $this->em->clear();
        self::assertSame(0, $this->em->getRepository(TransferRequest::class)->count([]));
    }

    public function testEditRefusedForATakenUsernameSavesNothing(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $this->createUser('999999999999', User::ROLE_TEACHER);

        $this->client->request('GET', $this->url($this->school, '/'.$learner->getId().'/edit'));
        $this->client->submitForm('Save', ['learner_form[lrn]' => '999999999999', 'learner_form[firstName]' => 'Anna']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'The username 999999999999 is already taken by another account. Ask the administrator.');
        $learner = $this->findLearner('111111111111');
        self::assertSame(['Ana', 'Abad, Ana', '111111111111'], [$learner->getFirstName(), $learner->getUser()->getFullName(), $learner->getUser()->getUsername()]);
        self::assertSame([], $this->activityActions());
    }

    public function testEditRefusedWhileTheLearnerIsBeingChangedSavesNothing(): void
    {
        $learner = $this->enrolled('111111111111', 'Abad', 'Ana');
        $this->client->request('GET', $this->url($this->school, '/'.$learner->getId().'/edit'));

        // another request is in the middle of changing this learner
        $lock = static::getContainer()->get(LockFactory::class)->createLock('learner-'.$learner->getId());
        self::assertTrue($lock->acquire());
        try {
            $this->client->submitForm('Save', ['learner_form[lrn]' => '888888888888', 'learner_form[firstName]' => 'Anna']);
        } finally {
            $lock->release();
        }

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('.form-card', 'Another change is being saved. Please try again.');
        $learner = $this->findLearner('111111111111');
        self::assertSame(['Ana', 'Abad, Ana', '111111111111'], [$learner->getFirstName(), $learner->getUser()->getFullName(), $learner->getUser()->getUsername()]);
        self::assertSame([], $this->activityActions());
    }
}
