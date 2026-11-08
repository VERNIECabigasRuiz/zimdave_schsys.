<?php

namespace App\Tests\StaffId;

use App\Schools\Domain\Model\Division;
use App\Entity\PersonalDataSheet;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use App\Entity\ServiceRecordEntry;
use App\Learners\Domain\Model\SexAtBirth;
use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\Repository\DivisionRepositoryInterface;
use App\Repository\PersonalDataSheetRepository;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Repository\ServiceRecordEntryRepository;
use App\Repository\StaffIdDetailsRepository;
use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use App\StaffId\StaffIdCardBuilder;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;

class StaffIdCardBuilderTest extends KernelTestCase
{
    use ClockSensitiveTrait;
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private User $rosa;
    private User $tess;

    protected function setUp(): void
    {
        self::bootKernel();
        static::mockTime('2026-11-02 10:00:00');
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2026, current: true);
        $this->school = $this->school('303196', 'Pagsabungan NHS');
        $this->rosa = $this->plainUser('rosa', User::ROLE_PRINCIPAL)->setFullName('Rosa Cruz');
        $this->assign($this->school, $this->sy, $this->rosa);
        $this->tess = $this->plainUser('tess', User::ROLE_TEACHER)->setFullName('Tess Reyes');
        $this->assign($this->school, $this->sy, $this->tess, User::ROLE_TEACHER);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    /** Built by hand: until a controller uses it, the container drops the service. */
    private function builder(): StaffIdCardBuilder
    {
        $c = static::getContainer();

        return new StaffIdCardBuilder(
            $c->get(PersonalDataSheetRepository::class),
            $c->get(ServiceRecordEntryRepository::class),
            $c->get(StaffIdDetailsRepository::class),
            $c->get(SchoolAssignmentRepositoryInterface::class),
            $c->get(SchoolYearRepositoryInterface::class),
            $c->get(DivisionRepositoryInterface::class),
            $c->get(ImageProcessorInterface::class),
            $c->getParameter('kernel.project_dir'),
        );
    }

    private function pds(User $user, string $surname, string $first, ?string $middle, ?string $extension = null): PersonalDataSheet
    {
        $sheet = new PersonalDataSheet($user);
        $sheet->surname = $surname;
        $sheet->firstName = $first;
        $sheet->middleName = $middle;
        $sheet->nameExtension = $extension;
        $this->em->persist($sheet);
        $this->em->flush();

        return $sheet;
    }

    private function present(User $user, string $designation): void
    {
        $this->em->persist((new ServiceRecordEntry($user, new \DateTimeImmutable('2020-01-01'), $this->rosa, new \DateTimeImmutable()))
            ->setDesignation($designation)->setStatus('Regular/Permanent')->setAnnualSalary('300000')
            ->setStation('Mandaue City')->setBranch('National'));
        $this->em->flush();
    }

    private function photo(User $user): void
    {
        $path = 'uploads/photos/test_'.bin2hex(random_bytes(4)).'.webp';
        $absolute = static::getContainer()->getParameter('app.upload_root').'/'.$path;
        @mkdir(\dirname($absolute), 0o777, true);
        imagewebp(imagecreatetruecolor(35, 45), $absolute);
        $user->setPhotoPath($path);
        $this->em->flush();
    }

    public function testTeacherCardShowsPdsDataAndPrincipalAsHead(): void
    {
        $sheet = $this->pds($this->tess, 'Dela Cruz', 'Juan', 'Abad');
        $sheet->agencyEmployeeNo = '4693599';
        $sheet->tin = '123-456-789-000';
        $sheet->philhealthNo = '12-123456789-0';
        $sheet->pagibigNo = '1234-5678-9012';
        $sheet->bloodType = 'O+';
        $this->school->setFacebookUrl('https://facebook.com/pnhs')->setAddress('Pagsabungan, Mandaue City');
        $details = static::getContainer()->get(StaffIdDetailsRepository::class)->findOrNew($this->tess)
            ->setEmergencyName('Maria Dela Cruz')->setEmergencyContact('0917 123 4567')->setGsisPolicyNo('2003334189');
        $this->em->persist($details);
        $this->em->flush();
        $this->present($this->tess, 'Teacher I');
        $this->present($this->rosa, 'Principal II');

        $card = $this->builder()->build($this->tess, $this->school);

        self::assertSame('JUAN A. DELA CRUZ', $card->fullName);
        self::assertSame('DELA CRUZ', $card->surname);
        self::assertSame('JUAN A.', $card->givenNames);
        self::assertSame('Teacher I', $card->position);
        self::assertSame('4693599', $card->employeeNo);
        self::assertSame('123-456-789-000', $card->tin);
        self::assertSame('12-123456789-0', $card->philhealthNo);
        self::assertSame('1234-5678-9012', $card->pagibigNo);
        self::assertSame('O+', $card->bloodType);
        self::assertSame('2003334189', $card->gsisPolicyNo);
        self::assertSame('Maria Dela Cruz', $card->emergencyName);
        self::assertSame('0917 123 4567', $card->emergencyContact);
        self::assertSame('Region VII', $card->region);
        self::assertSame('Mandaue City', $card->division);
        self::assertSame('Pagsabungan NHS', $card->schoolName);
        self::assertSame('Pagsabungan, Mandaue City', $card->schoolAddress);
        self::assertSame('facebook.com/pnhs', $card->website);
        self::assertSame('ROSA CRUZ', $card->headName);
        self::assertSame('Principal II', $card->headPosition);
        self::assertNull($card->headSignature);
        self::assertNotNull($card->seal);
    }

    public function testOnlyTheCurrentPrincipalsSignaturePrints(): void
    {
        $path = 'uploads/signatures/'.bin2hex(random_bytes(16)).'.webp';
        $absolute = static::getContainer()->getParameter('app.upload_root').'/'.$path;
        @mkdir(\dirname($absolute), 0o777, true);
        imagewebp(imagecreatetruecolor(60, 20), $absolute);
        $this->school->setIdSignaturePath($path)->setIdSignatureBy($this->rosa);
        $this->em->flush();

        self::assertNotNull($this->builder()->build($this->tess, $this->school)->headSignature);

        // last year's principal uploaded it; the new principal hasn't yet
        $this->school->setIdSignatureBy($this->plainUser('oldhead', User::ROLE_PRINCIPAL));
        $this->em->flush();
        $card = $this->builder()->build($this->tess, $this->school);
        unlink($absolute);

        self::assertSame('ROSA CRUZ', $card->headName);
        self::assertNull($card->headSignature);
    }

    public function testPrincipalWithoutPresentRowFallsBack(): void
    {
        $card = $this->builder()->build($this->tess, $this->school);

        self::assertSame('ROSA CRUZ', $card->headName);
        self::assertSame('School Principal', $card->headPosition);
    }

    public function testPrincipalCardShowsSds(): void
    {
        $this->em->persist((new Division())->setRegion('Region VII')->setName('Mandaue City')->setSdsName('Dr. Maria Santos'));
        $this->em->flush();

        $card = $this->builder()->build($this->rosa, $this->school);

        self::assertSame('DR. MARIA SANTOS', $card->headName);
        self::assertSame('Schools Division Superintendent', $card->headPosition);
    }

    public function testNoDivisionMeansBlankHead(): void
    {
        $card = $this->builder()->build($this->rosa, $this->school);

        self::assertNull($card->headName);
        self::assertNull($card->headPosition);
        self::assertNull($card->headSignature);
    }

    public function testNoPrincipalMeansBlankHead(): void
    {
        $other = $this->school('303197', 'Solo School');
        $solo = $this->plainUser('solo', User::ROLE_TEACHER);
        $this->assign($other, $this->sy, $solo, User::ROLE_TEACHER);

        $card = $this->builder()->build($solo, $other);

        self::assertNull($card->headName);
        self::assertNull($card->headPosition);
    }

    public function testNameFallsBackToAccount(): void
    {
        $card = $this->builder()->build($this->tess, $this->school);

        self::assertSame('TESS REYES', $card->fullName);
        self::assertSame('REYES', $card->surname);
        self::assertSame('TESS', $card->givenNames);
        self::assertNull($card->employeeNo);
        self::assertNull($card->website);
    }

    public function testExtensionIsKept(): void
    {
        $this->pds($this->tess, 'Peña', 'Juan', 'Abad', 'Jr.');

        $card = $this->builder()->build($this->tess, $this->school);

        self::assertSame('JUAN A. PEÑA JR.', $card->fullName);
        self::assertSame('PEÑA', $card->surname);
        self::assertSame('JUAN A. JR.', $card->givenNames);
    }

    public function testCallNameAndRegionLabel(): void
    {
        $sheet = $this->pds($this->tess, 'Dela Cruz', 'Juan', 'Abad');

        self::assertSame('JUAN', $this->builder()->build($this->tess, $this->school)->callName);

        $sheet->sexAtBirth = SexAtBirth::Male;
        $this->em->flush();
        $card = $this->builder()->build($this->tess, $this->school);
        self::assertSame('Sir JUAN', $card->callName);
        self::assertSame('REGION VII - CENTRAL VISAYAS', $card->regionLabel);

        $sheet->sexAtBirth = SexAtBirth::Female;
        $this->em->flush();
        self::assertSame("Ma'am JUAN", $this->builder()->build($this->tess, $this->school)->callName);

        $details = static::getContainer()->get(StaffIdDetailsRepository::class)->findOrNew($this->tess)->setCallName('Sir Jun');
        $this->em->persist($details);
        $this->em->flush();
        self::assertSame('Sir Jun', $this->builder()->build($this->tess, $this->school)->callName);
    }

    public function testMissing(): void
    {
        self::assertSame(['photo', 'position'], $this->builder()->missing($this->tess));
        self::assertFalse($this->builder()->canPrint($this->tess));

        $this->photo($this->tess);
        $this->present($this->tess, 'Teacher I');

        self::assertSame([], $this->builder()->missing($this->tess));
        self::assertTrue($this->builder()->canPrint($this->tess));
        self::assertNotNull($this->builder()->build($this->tess, $this->school)->photo);
        unlink(static::getContainer()->getParameter('app.upload_root').'/'.$this->tess->getPhotoPath());
    }
}
