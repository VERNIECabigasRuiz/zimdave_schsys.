<?php

namespace App\Tests\Service;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\Learner;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\Sf1Remark;
use App\Entity\User;
use App\Repository\EnrollmentRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\TransferRequestRepository;
use App\Sf1\Sf1ReportBuilder;
use App\Sf1\Sf1Remarks;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class Sf1ReportBuilderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private User $adviser;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-01-27 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER)->setFullName('Dave Sosmeña');
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic')->setAdviser($this->adviser);
        $this->assign($this->school, $this->sy, $this->plainUser('rene', User::ROLE_PRINCIPAL)->setFullName('Rene Petancio'));
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function builder(): Sf1ReportBuilder
    {
        $c = static::getContainer();

        return new Sf1ReportBuilder(
            $c->get(EnrollmentRepository::class),
            $c->get(SchoolAssignmentRepository::class),
            new Sf1Remarks($c->get(TransferRequestRepository::class)),
            $c->get(ClockInterface::class),
        );
    }

    private function enrolled(string $lrn, string $last, string $first, SexAtBirth $sex, ?Section $section = null): Learner
    {
        $learner = $this->learner($lrn, $last, $first)->setSex($sex)->setMiddleName('Molina')
            ->setBirthDate(new \DateTimeImmutable('2012-03-20'))->setMotherTongue('Cebuano');
        $this->enroll($learner, $this->sy, $section ?? $this->artistic, $this->adviser);

        return $learner;
    }

    public function testMalesThenFemalesOrdered(): void
    {
        $this->enrolled('111111111111', 'Almodiel', 'Samantha', SexAtBirth::Female);
        $this->enrolled('222222222222', 'Vega', 'Emmanuel', SexAtBirth::Male);
        $this->enrolled('333333333333', 'Arnol', 'Gerald', SexAtBirth::Male);

        $report = $this->builder()->build($this->artistic);

        self::assertSame(['ARNOL, GERALD, MOLINA', 'VEGA, EMMANUEL, MOLINA'], array_map(static fn ($r) => $r->name, $report->male));
        self::assertSame(['ALMODIEL, SAMANTHA, MOLINA'], array_map(static fn ($r) => $r->name, $report->female));
    }

    public function testRowValues(): void
    {
        $this->enrolled('120017170001', 'Arnol', 'Gerald', SexAtBirth::Male)->setAddressBarangay('Tabok')
            ->setFatherLastName('Arnol')->setFatherFirstName('Gerald');
        $this->em->flush();

        $row = $this->builder()->build($this->artistic)->male[0];

        self::assertSame('120017170001', $row->lrn);
        self::assertSame('M', $row->sex);
        self::assertSame('03-20-2012', $row->birthDate);
        self::assertSame(13, $row->age);
        self::assertSame('Tabok', $row->barangay);
        self::assertSame('ARNOL, GERALD', $row->father);
        self::assertNull($row->mother);
        self::assertSame('Face to Face', $row->learningModality);
        self::assertNull($row->remarks);
    }

    public function testRemarksComeFromTheEnrollment(): void
    {
        $learner = $this->enrolled('111111111111', 'Arnol', 'Gerald', SexAtBirth::Male);
        $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $learner])
            ->setRemarkCode(Sf1Remark::Sned)->setRemarkDetails('Hearing');
        $this->em->flush();

        self::assertSame('SNED Hearing', $this->builder()->build($this->artistic)->male[0]->remarks);
    }

    public function testMotherTongueOnlyForGradesOneToThree(): void
    {
        $this->enrolled('111111111111', 'Arnol', 'Gerald', SexAtBirth::Male);
        $grade2 = $this->newSection($this->school, $this->sy, GradeLevel::Grade2, 'Rose');
        $this->enrolled('222222222222', 'Vega', 'Emmanuel', SexAtBirth::Male, $grade2);

        self::assertNull($this->builder()->build($this->artistic)->male[0]->motherTongue);
        self::assertSame('Cebuano', $this->builder()->build($grade2)->male[0]->motherTongue);
    }

    public function testEosyOnlyAfterTheSchoolYearEnds(): void
    {
        $this->enrolled('111111111111', 'Arnol', 'Gerald', SexAtBirth::Male);
        $dropped = $this->enrolled('222222222222', 'Vega', 'Emmanuel', SexAtBirth::Male);
        $this->enrolled('333333333333', 'Almodiel', 'Samantha', SexAtBirth::Female);
        $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $dropped])->setRemarkCode(Sf1Remark::Dropped);
        $this->em->flush();

        $report = $this->builder()->build($this->artistic);
        self::assertNull($report->eosyMale);
        self::assertNull($report->eosyFemale);

        static::mockTime('2026-05-15');
        $report = $this->builder()->build($this->artistic);
        self::assertSame(1, $report->eosyMale);
        self::assertSame(1, $report->eosyFemale);
    }

    public function testHeaderAndSignatories(): void
    {
        $report = $this->builder()->build($this->artistic);

        self::assertSame('303196', $report->schoolId);
        self::assertSame('Region VII', $report->region);
        self::assertSame('Mandaue City', $report->division);
        self::assertSame('Pagsabungan National High School', $report->schoolName);
        self::assertSame('2025 - 2026', $report->schoolYear);
        self::assertSame('Grade 8', $report->gradeLevel);
        self::assertSame('ARTISTIC', $report->section);
        self::assertSame('DAVE SOSMEÑA', $report->adviserName);
        self::assertSame('RENE PETANCIO', $report->schoolHeadName);
        self::assertEquals(new \DateTimeImmutable('2026-01-27 10:00:00'), $report->generatedOn);
    }

    public function testNoAdviserNoPrincipal(): void
    {
        $section = $this->newSection($this->school('303197', 'Other NHS'), $this->sy, GradeLevel::Grade8, 'Diligence');

        $report = $this->builder()->build($section);

        self::assertNull($report->adviserName);
        self::assertNull($report->schoolHeadName);
    }

    public function testEmptySectionAndFileName(): void
    {
        $report = $this->builder()->build($this->artistic);

        self::assertSame([], $report->male);
        self::assertSame([], $report->female);
        self::assertSame('SF1_2025_Grade 8 - ARTISTIC.xls', $report->fileName());
    }
}
