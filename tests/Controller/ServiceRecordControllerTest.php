<?php

namespace App\Tests\Controller;

use App\Entity\PersonalDataSheet;
use App\Entity\School;
use App\Entity\SchoolAssignment;
use App\Entity\SchoolYear;
use App\Entity\ServiceRecord;
use App\Entity\ServiceRecordEntry;
use App\Entity\User;
use App\Tests\Controller\Admin\AdminTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

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
        return \sprintf('/schools/%d/service-records/%d%s', ($school ?? $this->school)->getId(), $employee->getId(), $path);
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
    protected function entry(User $employee, string $from, ?string $to, string $salary = '420588.00'): ServiceRecordEntry
    {
        $row = (new ServiceRecordEntry($employee, new \DateTimeImmutable($from), $this->rosa, new \DateTimeImmutable('2026-10-01')))
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
        $this->client->request('POST', $this->url($this->tess, '/bp'), ['bp_number' => '2003334189', '_token' => $this->csrfToken('service-record-bp')]);

        self::assertResponseRedirects($this->url($this->tess));
        $this->em->clear();
        self::assertSame('2003334189', $this->em->getRepository(ServiceRecord::class)->findOneBy(['employee' => $this->tess->getId()])?->getBpNumber());

        $this->client->request('POST', $this->url($this->tess, '/bp'), ['bp_number' => ' ', '_token' => $this->csrfToken('service-record-bp')]);
        $this->em->clear();
        self::assertNull($this->em->getRepository(ServiceRecord::class)->findOneBy(['employee' => $this->tess->getId()])?->getBpNumber());
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

    public function testTransferredTeacherFollowsTheNewSchool(): void
    {
        $this->entry($this->tess, '2025-01-01', null);
        $this->em->remove($this->em->getRepository(SchoolAssignment::class)->findOneBy(['user' => $this->tess]));
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
