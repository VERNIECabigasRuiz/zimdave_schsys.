<?php

namespace App\Tests\Service;

use App\Entity\Enrollment;
use App\Entity\GradeLevel;
use App\Entity\Learner;
use App\Entity\School;
use App\Entity\SchoolYear;
use App\Entity\Section;
use App\Entity\SexAtBirth;
use App\Entity\User;
use App\LearnerId\IdCard;
use App\LearnerId\IdCardBuilder;
use App\LearnerId\QrCode;
use App\Repository\EnrollmentRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Service\ImageProcessor;
use App\Tests\SchoolFixturesTrait;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class IdCardBuilderTest extends KernelTestCase
{
    use SchoolFixturesTrait;

    private EntityManagerInterface $em;
    private SchoolYear $sy;
    private School $school;
    private Section $artistic;
    private User $adviser;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->clearSchoolData();
        $this->sy = $this->schoolYear(2025, current: true);
        $this->school = $this->school('303196', 'Pagsabungan National High School');
        $this->adviser = $this->plainUser('dave', User::ROLE_TEACHER);
        $this->artistic = $this->newSection($this->school, $this->sy, GradeLevel::Grade8, 'Artistic');
    }

    protected function tearDown(): void
    {
        $this->clearSchoolData();
        parent::tearDown();
    }

    private function builder(): IdCardBuilder
    {
        $c = static::getContainer();

        return new IdCardBuilder(
            $c->get(EnrollmentRepository::class),
            $c->get(SchoolAssignmentRepository::class),
            $c->get(ImageProcessor::class),
            new QrCode(),
            $c->getParameter('kernel.project_dir'),
        );
    }

    private function card(Learner $learner): IdCard
    {
        $this->em->flush();

        return $this->builder()->forEnrollment($this->enroll($learner, $this->sy, $this->artistic, $this->adviser));
    }

    public function testDisplayName(): void
    {
        $gerald = $this->learner('111111111111', 'Arnol', 'Gerald James')->setMiddleName('Molina');
        $remuel = $this->learner('222222222222', 'Bañez', 'Remuel')->setMiddleName('Bravo')->setExtension('Jr');
        $justine = $this->learner('333333333333', 'Borja', 'Justine');

        self::assertSame('GERALD JAMES M. ARNOL', $this->card($gerald)->name);
        self::assertSame('REMUEL B. BAÑEZ JR', $this->card($remuel)->name);
        self::assertSame('JUSTINE BORJA', $this->card($justine)->name);
    }

    public function testEmergencyContactOrder(): void
    {
        $learner = $this->learner('111111111111', 'Arnol', 'Gerald')->setContactNumber('09171234567')
            ->setGuardianName('Nena Abad')->setGuardianRelationship('Aunt')
            ->setMotherLastName('Molina')->setMotherFirstName('Angelle')
            ->setFatherLastName('Arnol')->setFatherFirstName('Gerald');
        $enrollment = $this->enroll($learner, $this->sy, $this->artistic, $this->adviser);
        $contact = fn () => [($c = $this->builder()->forEnrollment($enrollment))->emergencyLabel, $c->emergencyName, $c->emergencyNumber];

        self::assertSame(['Guardian (Aunt)', 'Nena Abad', '09171234567'], $contact());
        $learner->setGuardianName(null);
        self::assertSame(['Mother', 'MOLINA, ANGELLE', '09171234567'], $contact());
        $learner->setMotherLastName(null)->setMotherFirstName(null);
        self::assertSame(['Father', 'ARNOL, GERALD', '09171234567'], $contact());
        $learner->setFatherLastName(null)->setFatherFirstName(null);
        self::assertSame([null, null, '09171234567'], $contact());
    }

    public function testAddressWithoutStreet(): void
    {
        $learner = $this->learner('111111111111', 'Arnol', 'Gerald')->setAddressStreet('Purok 3')
            ->setAddressBarangay('Tabok')->setAddressProvince('Cebu');
        $enrollment = $this->enroll($learner, $this->sy, $this->artistic, $this->adviser);

        self::assertSame('Tabok, Cebu', $this->builder()->forEnrollment($enrollment)->address);
        $learner->setAddressBarangay(null)->setAddressProvince(null);
        self::assertNull($this->builder()->forEnrollment($enrollment)->address);
    }

    public function testSchoolValues(): void
    {
        $this->assign($this->school, $this->sy, $this->plainUser('rene', User::ROLE_PRINCIPAL)->setFullName('Rene Petancio'));
        $card = $this->card($this->learner('120017170001', 'Arnol', 'Gerald'));

        self::assertSame('Pagsabungan National High School', $card->schoolName);
        self::assertSame('303196', $card->schoolId);
        self::assertSame('Region VII', $card->region);
        self::assertSame('Mandaue City', $card->division);
        self::assertSame('120017170001', $card->lrn);
        self::assertSame('Grade 8 – ARTISTIC', $card->gradeSection);
        self::assertSame('RENE PETANCIO', $card->schoolHead);
        self::assertSame('2025–2026', $card->schoolYear);
        self::assertEquals(new \DateTimeImmutable('2026-04-30'), $card->validUntil);
        self::assertStringStartsWith('data:image/png;base64,', $card->qr);
        self::assertStringStartsWith('data:image/png;base64,', $card->seal);
    }

    public function testMissingImagesAreNull(): void
    {
        $learner = $this->learner('111111111111', 'Arnol', 'Gerald');
        $card = $this->card($learner);
        self::assertNull($card->photo);
        self::assertNull($card->logo);

        $path = 'uploads/photos/'.str_repeat('a', 32).'.webp';
        $file = static::getContainer()->getParameter('app.upload_root').'/'.$path;
        @mkdir(\dirname($file), 0777, true);
        imagewebp(imagecreatetruecolor(350, 450), $file);
        $learner->getUser()->setPhotoPath($path);
        try {
            $enrollment = $this->em->getRepository(Enrollment::class)->findOneBy(['learner' => $learner]);
            self::assertStringStartsWith('data:image/png;base64,', $this->builder()->forEnrollment($enrollment)->photo);
        } finally {
            unlink($file);
        }
    }

    public function testNoPrincipal(): void
    {
        self::assertNull($this->card($this->learner('111111111111', 'Arnol', 'Gerald'))->schoolHead);
    }

    public function testForSectionKeepsClassListOrder(): void
    {
        foreach ([['111111111111', 'Almodiel', SexAtBirth::Female], ['222222222222', 'Vega', SexAtBirth::Male], ['333333333333', 'Arnol', SexAtBirth::Male]] as [$lrn, $last, $sex]) {
            $this->enroll($this->learner($lrn, $last, 'X')->setSex($sex), $this->sy, $this->artistic, $this->adviser);
        }

        $lrns = array_map(static fn (IdCard $c) => $c->lrn, $this->builder()->forSection($this->artistic));

        self::assertSame(['333333333333', '222222222222', '111111111111'], $lrns);
    }
}
