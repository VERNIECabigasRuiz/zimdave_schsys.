<?php

namespace App\Tests\ServiceRecord;

use App\Entity\PersonalDataSheet;
use App\Entity\School;
use App\Entity\ServiceRecord;
use App\Entity\ServiceRecordEntry;
use App\Access\Domain\Model\User;
use App\ServiceRecord\ServiceRecordPdf;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class ServiceRecordPdfTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private School $school;
    private User $rosa;
    private User $tess;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-02 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->rosa = $this->plainUser('rosa', User::ROLE_PRINCIPAL)->setFullName('Rosa Cruz');
        $this->assign($this->school, $sy, $this->rosa);
        $this->tess = $this->plainUser('tess', User::ROLE_TEACHER);
        $this->assign($this->school, $sy, $this->tess, User::ROLE_TEACHER);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function pdf(): ServiceRecordPdf
    {
        return static::getContainer()->get(ServiceRecordPdf::class);
    }

    private function pds(): void
    {
        $sheet = new PersonalDataSheet($this->tess);
        $sheet->surname = 'Dela Cruz';
        $sheet->firstName = 'Juan';
        $sheet->middleName = 'Abad';
        $sheet->dateOfBirth = new \DateTimeImmutable('1991-04-25');
        $sheet->placeOfBirth = 'Cebu City';
        $sheet->agencyEmployeeNo = '4693599';
        $this->em->persist($sheet);
        $this->em->flush();
    }

    private function entry(string $from, ?string $to, string $salary, ?string $remarks = null): void
    {
        $this->em->persist((new ServiceRecordEntry($this->tess, new \DateTimeImmutable($from), $this->rosa, new \DateTimeImmutable()))
            ->setToDate(null === $to ? null : new \DateTimeImmutable($to))
            ->setDesignation('Teacher III')->setStatus('Regular/Permanent')->setAnnualSalary($salary)
            ->setStation('Mandaue City')->setBranch('National')->setRemarks($remarks));
        $this->em->flush();
    }

    public function testRowsAndNothingFollows(): void
    {
        $this->entry('2026-01-01', '2026-07-19', '439188.00');
        $this->entry('2026-07-20', null, '443028.00', 'SG13-4 NBC-601');

        $html = $this->pdf()->html($this->tess, $this->school);

        self::assertStringContainsString('07/20/2026', $html);
        self::assertStringContainsString('07/19/2026', $html);
        self::assertStringContainsString('Present', $html);
        self::assertStringContainsString('443,028.00', $html);
        self::assertStringContainsString('SG13-4 NBC-601', $html);
        self::assertStringContainsString('NOTHING FOLLOWS', $html);
        self::assertStringContainsString('Issued in compliance with Executive Order No. 54 dated August 10, 1954', $html);
        self::assertLessThan(strpos($html, '07/20/2026'), strpos($html, '07/19/2026'));
    }

    public function testHeaderFromPdsAndBpNumber(): void
    {
        $this->pds();
        $this->em->persist((new ServiceRecord($this->tess))->setBpNumber('2003334189'));
        $this->em->flush();

        $html = $this->pdf()->html($this->tess, $this->school);

        foreach (['DELA CRUZ', 'JUAN', 'ABAD', 'April 25, 1991', 'Cebu City', '4693599', '2003334189', 'PAGSABUNGAN NHS'] as $text) {
            self::assertStringContainsString($text, $html);
        }
    }

    public function testSignatoriesFromSettings(): void
    {
        $this->school->setSrPreparedByName('JANET T. BIHAG')->setSrPreparedByDesignation('Teacher III')
            ->setSrCertifiedByName('EDGAR C. ESPINA JR., DevEdD')->setSrCertifiedByDesignation('Administrative Officer V');
        $this->em->flush();

        $html = $this->pdf()->html($this->tess, $this->school);

        foreach (['JANET T. BIHAG', 'EDGAR C. ESPINA JR., DevEdD', 'Administrative Officer V', 'Chief or Head of Office', 'November 2, 2026'] as $text) {
            self::assertStringContainsString($text, $html);
        }
        self::assertStringNotContainsString('Rosa Cruz', $html);
    }

    public function testCertifiedCorrectFallsBackToThePrincipal(): void
    {
        $html = $this->pdf()->html($this->tess, $this->school);

        self::assertStringContainsString('ROSA CRUZ', $html);
        self::assertStringContainsString('School Principal', $html);
    }

    public function testNoPdsStillRenders(): void
    {
        $bytes = $this->pdf()->render($this->tess, $this->school);

        self::assertStringStartsWith('%PDF', $bytes);
        self::assertStringContainsString('NOTHING FOLLOWS', $this->pdf()->html($this->tess, $this->school));
    }

    public function testFilename(): void
    {
        self::assertSame('Service record.pdf', $this->pdf()->filename($this->tess));

        $this->pds();
        self::assertSame('Service record - DELA CRUZ, JUAN.pdf', $this->pdf()->filename($this->tess));
    }
}
