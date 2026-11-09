<?php

declare(strict_types=1);

namespace App\Tests\Staff\Functional;

use App\Staff\Domain\Model\PersonalDataSheet;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Schools\Domain\Model\SchoolYear;
use App\Staff\Domain\Model\ServiceRecord;
use App\Staff\Domain\Model\ServiceRecordEntry;
use App\Access\Domain\Model\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FormField;
use Symfony\Component\DomCrawler\Form;

class ServiceRecordControllerTest extends AdminTestCase
{
    use ClockSensitiveTrait;

    private SchoolYear $sy;
    private School $school;
    private School $other;
    private User $rosa;
    private User $tess;

    protected function setUp(): void
    {
        parent::setUp();
        static::mockTime('2026-11-02 10:00:00');
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->school->setAddress('Z. Estreras, Pagsabungan, Mandaue City');
        $this->other = $this->school('303197', 'Other NHS');
        $this->rosa = $this->createUser('rosa', User::ROLE_PRINCIPAL, 'Rosa Cruz');
        $this->assign($this->school, $this->sy, $this->rosa);
        $this->tess = $this->createUser('tess', User::ROLE_TEACHER, 'Tess Reyes');
        $this->assign($this->school, $this->sy, $this->tess, User::ROLE_TEACHER);
        $this->em->flush();
        $this->client->loginUser($this->rosa);
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function url(User $employee, string $path = '', ?School $school = null): string
    {
        return \sprintf(
            '/schools/%d/service-records/%d%s',
            ($school ?? $this->school)->getId(),
            $employee->getId(),
            $path,
        );
    }

    private function pds(User $user): PersonalDataSheet
    {
        $sheet = new PersonalDataSheet($user);
        $sheet->surname = 'Dela Cruz';
        $sheet->firstName = 'Juan';
        $sheet->middleName = 'Abad';
        $sheet->dateOfBirth = new \DateTimeImmutable('1991-04-25');
        $sheet->placeOfBirth = 'Cebu City';
        $sheet->agencyEmployeeNo = '4693599';
        $this->em->persist($sheet);
        $this->em->flush();

        return $sheet;
    }

    /** A saved row of $employee's record. Dates as Y-m-d. */
    protected function entry(
        User $employee,
        string $from,
        ?string $to,
        string $salary = '420588.00',
    ): ServiceRecordEntry {
        $row = (new ServiceRecordEntry(
            $employee,
            new \DateTimeImmutable($from),
            $this->rosa,
            new \DateTimeImmutable('2026-10-01'),
        ))
            ->setToDate(null === $to ? null : new \DateTimeImmutable($to))
            ->setDesignation('Teacher III')
            ->setStatus('Regular/Permanent')
            ->setAnnualSalary($salary)
            ->setStation('Mandaue City')
            ->setBranch('National');
        $this->em->persist($row);
        $this->em->flush();

        return $row;
    }

    public function testPrincipalOpensATeachersRecord(): void
    {
        $this->pds($this->tess);

        $this->client->request('GET', $this->url($this->tess));

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Service record');
        self::assertSelectorTextContains('.service-record__header', 'DELA CRUZ');
        self::assertSelectorTextContains('.service-record__header', '4693599');
        self::assertSelectorNotExists('.alert--warning');
    }

    public function testMissingPdsDataIsListed(): void
    {
        $this->client->request('GET', $this->url($this->tess));

        self::assertSelectorTextContains('.alert--warning', 'Surname');
        self::assertSelectorTextContains('.alert--warning', 'Date of birth');
    }

    public function testPrincipalOpensOwnRecordFromTheMenu(): void
    {
        $this->client->request('GET', '/principal/service-record');

        self::assertResponseRedirects($this->url($this->rosa));
    }

    public function testSavesBpNumber(): void
    {
        $this->client->request('GET', $this->url($this->tess));
        $this->client->request(
            'POST',
            $this->url($this->tess, '/bp'),
            ['bp_number' => '2003334189', '_token' => $this->csrfToken('service-record-bp')],
        );

        self::assertResponseRedirects($this->url($this->tess));
        $this->em->clear();
        $record = $this->em->getRepository(ServiceRecord::class)->findOneBy(['employee' => $this->tess->getId()]);
        self::assertSame('2003334189', $record?->getBpNumber());

        $this->client->request(
            'POST',
            $this->url($this->tess, '/bp'),
            ['bp_number' => ' ', '_token' => $this->csrfToken('service-record-bp')],
        );
        $this->em->clear();
        $record = $this->em->getRepository(ServiceRecord::class)->findOneBy(['employee' => $this->tess->getId()]);
        self::assertNull($record?->getBpNumber());
    }

    public function testAdminGets403OnThePageAndBp(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('GET', $this->url($this->tess));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url($this->tess, '/bp'), ['bp_number' => '1']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTeacherGets403OnOwnRecord(): void
    {
        $this->client->loginUser($this->tess);

        $this->client->request('GET', $this->url($this->tess));

        self::assertResponseStatusCodeSame(403);
    }

    public function testOtherSchoolsPrincipalGets403(): void
    {
        $omar = $this->createUser('omar', User::ROLE_PRINCIPAL);
        $this->assign($this->other, $this->sy, $omar);
        $this->client->loginUser($omar);

        $this->client->request('GET', $this->url($this->tess));

        self::assertResponseStatusCodeSame(403);
    }

    public function testPersonOfAnotherSchoolIs404(): void
    {
        $owen = $this->createUser('owen', User::ROLE_TEACHER);
        $this->assign($this->other, $this->sy, $owen, User::ROLE_TEACHER);

        $this->client->request('GET', $this->url($owen));

        self::assertResponseStatusCodeSame(404);
    }

    /** @return list<array{string, ?string, string}> from, to (Y-m-d) and salary of $employee's rows, oldest first */
    private function rows(User $employee): array
    {
        $this->em->clear();

        return array_map(
            static fn (ServiceRecordEntry $r) => [
                (string) $r->getFromDate()?->format('Y-m-d'),
                $r->getToDate()?->format('Y-m-d'),
                (string) $r->getAnnualSalary(),
            ],
            $this->em->getRepository(ServiceRecordEntry::class)->findBy(
                ['employee' => $employee->getId()],
                ['fromDate' => 'ASC'],
            ),
        );
    }

    /** The value of the row form's $name field. */
    private function value(Form $form, string $name): string
    {
        $field = $form[$this->field($name)];
        self::assertInstanceOf(FormField::class, $field);
        $value = $field->getValue();
        self::assertIsString($value);

        return $value;
    }

    /** $row as saved now. */
    private function stored(ServiceRecordEntry $row): ServiceRecordEntry
    {
        $stored = $this->em->getRepository(ServiceRecordEntry::class)->find($row->getId());
        self::assertNotNull($stored);

        return $stored;
    }

    private function field(string $name): string
    {
        return 'service_record_entry_form[' . $name . ']';
    }

    public function testAddFirstRowPrefillsStationFromTheSchool(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->tess, '/rows/new'));

        self::assertResponseIsSuccessful();
        $form = $crawler->selectButton('Save row')->form();
        self::assertSame('2026-11-02', $this->value($form, 'fromDate'));
        self::assertSame('Mandaue City', $this->value($form, 'station'));
        self::assertSame('National', $this->value($form, 'branch'));
        self::assertCount(0, $crawler->filter('input[name="' . $this->field('closeOpen') . '"]'));
    }

    public function testAddRowPrefillsFromTheLastRow(): void
    {
        $this->entry($this->tess, '2025-01-01', '2025-12-31');

        $form = $this->client->request('GET', $this->url($this->tess, '/rows/new'))->selectButton('Save row')->form();

        self::assertSame('Teacher III', $this->value($form, 'designation'));
        self::assertSame('Regular/Permanent', $this->value($form, 'status'));
        self::assertSame('2026-01-01', $this->value($form, 'fromDate'));
    }

    public function testAddAfterAnOpenRowLeavesFromEmpty(): void
    {
        $this->entry($this->tess, '2026-01-01', null);

        $crawler = $this->client->request('GET', $this->url($this->tess, '/rows/new'));
        $form = $crawler->selectButton('Save row')->form();
        self::assertSame('', $this->value($form, 'fromDate'));
        self::assertSame('Teacher III', $this->value($form, 'designation'));

        // saved without a From, nothing changes: the current row stays open
        $this->client->submit($form, [$this->field('annualSalary') => '443028']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame([['2026-01-01', null, '420588.00']], $this->rows($this->tess));
    }

    public function testAddingAfterAnOpenRowClosesIt(): void
    {
        $this->entry($this->tess, '2026-01-01', null);

        $crawler = $this->client->request('GET', $this->url($this->tess, '/rows/new'));
        $this->client->submit($crawler->selectButton('Save row')->form([
            $this->field('fromDate') => '2026-07-20',
            $this->field('annualSalary') => '443028',
            $this->field('remarks') => 'SG13-4 NBC-601',
        ]));

        self::assertResponseRedirects($this->url($this->tess));
        self::assertSame(
            [['2026-01-01', '2026-07-19', '420588.00'], ['2026-07-20', null, '443028.00']],
            $this->rows($this->tess),
        );
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Row added.');
    }

    public function testUntickedCloseKeepsTheErrorAndSavesNothing(): void
    {
        $this->entry($this->tess, '2026-01-01', null);

        $form = $this->client->request('GET', $this->url($this->tess, '/rows/new'))->selectButton('Save row')->form(
            [$this->field('fromDate') => '2026-07-20'],
        );
        $closeOpen = $form[$this->field('closeOpen')];
        self::assertInstanceOf(ChoiceFormField::class, $closeOpen);
        $closeOpen->untick();
        $this->client->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'There is already a row up to the present. Close it first.');
        self::assertCount(1, $this->rows($this->tess));
    }

    public function testClosingOntoAnEarlierDateIsRefused(): void
    {
        $this->entry($this->tess, '2026-07-20', null);

        $crawler = $this->client->request('GET', $this->url($this->tess, '/rows/new'));
        $this->client->submit($crawler->selectButton('Save row')->form([$this->field('fromDate') => '2026-07-01']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'This service overlaps the row from 07/20/2026.');
        self::assertSame([['2026-07-20', null, '420588.00']], $this->rows($this->tess));
    }

    public function testEditRow(): void
    {
        $row = $this->entry($this->tess, '2025-01-01', '2025-12-31');
        $this->entry($this->tess, '2026-01-01', null);
        $this->stored($row)->touch(
            $this->tess,
            new \DateTimeImmutable('2026-10-01'),
        );
        $this->em->flush();

        $crawler = $this->client->request('GET', $this->url($this->tess, '/rows/' . $row->getId() . '/edit'));
        $this->client->submit($crawler->selectButton('Save row')->form([
            $this->field('annualSalary') => '420600.50',
            $this->field('remarks') => 'SG13-3 NBC-597',
        ]));

        self::assertResponseRedirects($this->url($this->tess));
        self::assertSame(['2025-01-01', '2025-12-31', '420600.50'], $this->rows($this->tess)[0]);
        self::assertSame(
            'Rosa Cruz',
            $this->stored($row)->getUpdatedBy()?->getFullName(),
        );
        $this->client->followRedirect();
        self::assertSelectorTextContains('.alert--success', 'Row saved.');
        self::assertSelectorTextContains('tr[data-entry="' . $row->getId() . '"]', 'Rosa Cruz');
    }

    public function testEditIntoOverlapIsRefused(): void
    {
        $row = $this->entry($this->tess, '2024-01-01', '2024-12-31');
        $this->entry($this->tess, '2025-01-01', '2025-12-31');

        $crawler = $this->client->request('GET', $this->url($this->tess, '/rows/' . $row->getId() . '/edit'));
        $this->client->submit($crawler->selectButton('Save row')->form([$this->field('toDate') => '2025-03-31']));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('main', 'This service overlaps the row from 01/01/2025.');
        self::assertSame(['2024-01-01', '2024-12-31', '420588.00'], $this->rows($this->tess)[0]);
    }

    public function testARefusedAddKeepsWhatWasTypedUnderItsTitle(): void
    {
        // Initialize
        $this->entry($this->tess, '2025-01-01', '2025-12-31');
        $crawler = $this->client->request('GET', $this->url($this->tess, '/rows/new'));
        $form = $crawler->selectButton('Save row')->form([
            $this->field('toDate') => '2025-06-30',
            $this->field('remarks') => 'SG13-4 NBC-601',
        ]);

        // Execute
        $page = $this->client->submit($form);

        // Assert
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('h1', 'Add row');
        self::assertSelectorTextContains('main', 'The end date is before the start date.');
        $typed = $page->selectButton('Save row')->form();
        self::assertSame('SG13-4 NBC-601', $this->value($typed, 'remarks'));
        self::assertSame('2025-06-30', $this->value($typed, 'toDate'));
        self::assertCount(1, $this->rows($this->tess));
    }

    public function testARefusedEditKeepsItsTitle(): void
    {
        // Initialize
        $row = $this->entry($this->tess, '2024-01-01', '2024-12-31');
        $this->entry($this->tess, '2025-01-01', '2025-12-31');
        $crawler = $this->client->request('GET', $this->url($this->tess, '/rows/' . $row->getId() . '/edit'));
        $form = $crawler->selectButton('Save row')->form([$this->field('toDate') => '2025-03-31']);

        // Execute
        $this->client->submit($form);

        // Assert
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('h1', 'Edit row');
        self::assertPageTitleContains('Edit service record row');
    }

    public function testAnUnknownSchoolIs404(): void
    {
        // Initialize
        $url = \sprintf('/schools/%d/service-records/%d', 999999, $this->tess->getId());

        // Execute
        $this->client->request('GET', $url);

        // Assert
        self::assertResponseStatusCodeSame(404);
    }

    public function testDeleteRow(): void
    {
        $row = $this->entry($this->tess, '2025-01-01', null);
        $this->client->request('GET', $this->url($this->tess));

        $this->client->request(
            'POST',
            $this->url($this->tess, '/rows/' . $row->getId() . '/delete'),
            ['_token' => 'bad'],
        );
        self::assertCount(1, $this->rows($this->tess));

        $this->client->request(
            'POST',
            $this->url($this->tess, '/rows/' . $row->getId() . '/delete'),
            ['_token' => $this->csrfToken('service-row-' . $row->getId())],
        );
        self::assertResponseRedirects($this->url($this->tess));
        self::assertCount(0, $this->rows($this->tess));
    }

    public function testOtherSchoolsPrincipalWithABadTokenIsRefused(): void
    {
        // Initialize
        $row = $this->entry($this->tess, '2025-01-01', null);
        $omar = $this->createUser('omar', User::ROLE_PRINCIPAL);
        $this->assign($this->other, $this->sy, $omar);
        $this->em->flush();
        $this->client->loginUser($omar);

        // Execute
        $this->client->request(
            'POST',
            $this->url($this->tess, '/rows/' . $row->getId() . '/delete'),
            ['_token' => 'bad'],
        );
        $deleteStatus = $this->client->getResponse()->getStatusCode();
        $this->client->request('POST', $this->url($this->tess, '/bp'), ['bp_number' => '1', '_token' => 'bad']);
        $bpStatus = $this->client->getResponse()->getStatusCode();

        // Assert
        self::assertSame([403, 403], [$deleteStatus, $bpStatus]);
        self::assertCount(1, $this->rows($this->tess));
    }

    public function testEntryOfAnotherPersonIs404(): void
    {
        $rosasRow = $this->entry($this->rosa, '2020-01-01', null);
        $this->client->request('GET', $this->url($this->tess));

        $this->client->request('GET', $this->url($this->tess, '/rows/' . $rosasRow->getId() . '/edit'));
        self::assertResponseStatusCodeSame(404);
        $this->client->request(
            'POST',
            $this->url($this->tess, '/rows/' . $rosasRow->getId() . '/delete'),
            ['_token' => $this->csrfToken('service-row-' . $rosasRow->getId())],
        );
        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $this->rows($this->rosa));
    }

    public function testPrincipalEditsOwnRecord(): void
    {
        $crawler = $this->client->request('GET', $this->url($this->rosa, '/rows/new'));
        $this->client->submit($crawler->selectButton('Save row')->form([
            $this->field('fromDate') => '2020-06-01',
            $this->field('designation') => 'Principal I',
            $this->field('status') => 'Regular/Permanent',
            $this->field('annualSalary') => '600000',
        ]));

        self::assertResponseRedirects($this->url($this->rosa));
        self::assertSame([['2020-06-01', null, '600000.00']], $this->rows($this->rosa));
    }

    public function testAdminCannotAddEditOrDelete(): void
    {
        $row = $this->entry($this->tess, '2025-01-01', null);
        $this->client->loginUser($this->admin);

        $this->client->request('GET', $this->url($this->tess, '/rows/new'));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', $this->url($this->tess, '/rows/' . $row->getId() . '/edit'));
        self::assertResponseStatusCodeSame(403);
        $this->client->request('POST', $this->url($this->tess, '/rows/' . $row->getId() . '/delete'));
        self::assertResponseStatusCodeSame(403);
        self::assertCount(1, $this->rows($this->tess));
    }

    public function testDownloadPdf(): void
    {
        $this->pds($this->tess);
        $this->entry($this->tess, '2025-01-01', null);

        $this->client->request('GET', $this->url($this->tess, '/service-record.pdf'));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertStringContainsString(
            'Service record - DELA CRUZ, JUAN.pdf',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
        $this->client->request('GET', $this->url($this->tess));
        self::assertSelectorExists('a[href="' . $this->url($this->tess, '/service-record.pdf') . '"]');
    }

    public function testPdfOfANameWithEnye(): void
    {
        $this->pds($this->tess)->surname = 'Peña';
        $this->em->flush();

        $this->client->request('GET', $this->url($this->tess, '/service-record.pdf'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'filename="Service record - PENA, JUAN.pdf"',
            (string) $this->client->getResponse()->headers->get('Content-Disposition'),
        );
    }

    public function testAdminDownloadsPdf(): void
    {
        $this->client->loginUser($this->admin);

        $this->client->request('GET', $this->url($this->rosa, '/service-record.pdf'));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
    }

    public function testTeacherCannotDownloadOwnPdf(): void
    {
        $this->client->loginUser($this->tess);

        $this->client->request('GET', $this->url($this->tess, '/service-record.pdf'));

        self::assertResponseStatusCodeSame(403);
    }

    public function testTeachersPageLinksToTheRecord(): void
    {
        $crawler = $this->client->request('GET', '/schools/' . $this->school->getId() . '/teachers');

        self::assertCount(1, $crawler->filter('a[href="' . $this->url($this->tess) . '"]'));
    }

    public function testAdminTeachersPageLinksToThePdf(): void
    {
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/schools/' . $this->school->getId() . '/teachers');

        self::assertCount(1, $crawler->filter('a[href="' . $this->url($this->tess, '/service-record.pdf') . '"]'));
        self::assertCount(0, $crawler->filter('a[href="' . $this->url($this->tess) . '"]'));
    }

    public function testAdminSchoolListLinksToThePrincipalsPdf(): void
    {
        $this->client->loginUser($this->admin);

        $crawler = $this->client->request('GET', '/admin/schools');

        self::assertCount(1, $crawler->filter('a[href="' . $this->url($this->rosa, '/service-record.pdf') . '"]'));
    }

    public function testMenuHasMyServiceRecord(): void
    {
        $this->client->request('GET', $this->url($this->rosa));
        self::assertSelectorTextContains('.sidebar__link.is-active', 'My Service Record');

        $this->client->request('GET', $this->url($this->tess));
        self::assertSelectorExists('.sidebar a[href="/principal/service-record"]');
        self::assertSelectorTextContains('.sidebar__link.is-active', 'Teachers');
    }

    public function testTransferredTeacherFollowsTheNewSchool(): void
    {
        $this->entry($this->tess, '2025-01-01', null);
        $assignment = $this->em->getRepository(SchoolAssignment::class)->findOneBy(['user' => $this->tess]);
        self::assertNotNull($assignment);
        $this->em->remove($assignment);
        $this->em->flush();
        $this->assign($this->other, $this->sy, $this->tess, User::ROLE_TEACHER);
        $omar = $this->createUser('omar', User::ROLE_PRINCIPAL);
        $this->assign($this->other, $this->sy, $omar);

        $this->client->request('GET', $this->url($this->tess));
        self::assertResponseStatusCodeSame(404);

        $this->client->loginUser($omar);
        $this->client->request('GET', $this->url($this->tess, school: $this->other));
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.service-record__rows', '01/01/2025');
        self::assertSelectorTextContains('.service-record__rows', 'Present');
    }
}
