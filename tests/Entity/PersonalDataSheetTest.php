<?php

namespace App\Tests\Entity;

use App\Entity\Citizenship;
use App\Entity\CivilStatus;
use App\Entity\PersonalDataSheet;
use App\Entity\SexAtBirth;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class PersonalDataSheetTest extends KernelTestCase
{
    private function validSheet(): PersonalDataSheet
    {
        $sheet = new PersonalDataSheet(new User());
        $sheet->surname = 'Dela Cruz';
        $sheet->firstName = 'Juan';
        $sheet->dateOfBirth = new \DateTimeImmutable('1990-05-01');
        $sheet->sexAtBirth = SexAtBirth::Male;
        $sheet->civilStatus = CivilStatus::Single;
        $sheet->citizenship = Citizenship::Filipino;

        return $sheet;
    }

    /** @return list<string> */
    private function violationPaths(PersonalDataSheet $sheet): array
    {
        $validator = static::getContainer()->get(ValidatorInterface::class);

        return array_map(static fn ($v) => $v->getPropertyPath(), iterator_to_array($validator->validate($sheet)));
    }

    public function testDisplayNameFormatsInitialAndExtension(): void
    {
        $sheet = $this->validSheet();
        $sheet->middleName = 'Abad';
        $sheet->nameExtension = 'Jr.';
        self::assertSame('Juan A. Dela Cruz Jr.', $sheet->displayName());

        $sheet->middleName = null;
        $sheet->nameExtension = null;
        self::assertSame('Juan Dela Cruz', $sheet->displayName());

        $sheet->surname = null;
        self::assertNull($sheet->displayName());
    }

    public function testSyncPermanentAddressCopiesWhenSame(): void
    {
        $sheet = $this->validSheet();
        $sheet->resHouseNo = '12';
        $sheet->resStreet = 'Rizal St.';
        $sheet->resSubdivision = 'Villa Uno';
        $sheet->resBarangay = 'Pagsabungan';
        $sheet->resCity = 'Mandaue City';
        $sheet->resProvince = 'Cebu';
        $sheet->resZip = '6014';

        $sheet->permSameAsResidential = false;
        $sheet->syncPermanentAddress();
        self::assertNull($sheet->permCity);

        $sheet->permSameAsResidential = true;
        $sheet->syncPermanentAddress();
        self::assertSame(
            ['12', 'Rizal St.', 'Villa Uno', 'Pagsabungan', 'Mandaue City', 'Cebu', '6014'],
            [$sheet->permHouseNo, $sheet->permStreet, $sheet->permSubdivision, $sheet->permBarangay, $sheet->permCity, $sheet->permProvince, $sheet->permZip],
        );
    }

    public function testValidSheetHasNoViolations(): void
    {
        self::assertSame([], $this->violationPaths($this->validSheet()));
    }

    public function testValidationRules(): void
    {
        $cases = [
            'dateOfBirth' => static fn (PersonalDataSheet $s) => $s->dateOfBirth = new \DateTimeImmutable('+1 day'),
            'mobileNo' => static fn (PersonalDataSheet $s) => $s->mobileNo = '9171234567',
            'resZip' => static fn (PersonalDataSheet $s) => $s->resZip = '60140',
            'civilStatusOther' => static fn (PersonalDataSheet $s) => $s->civilStatus = CivilStatus::Others,
            'dualCitizenshipCountry' => static fn (PersonalDataSheet $s) => $s->citizenship = Citizenship::Dual,
            'email' => static fn (PersonalDataSheet $s) => $s->email = 'not-an-email',
            'height' => static fn (PersonalDataSheet $s) => $s->height = '3.10',
            'bloodType' => static fn (PersonalDataSheet $s) => $s->bloodType = 'C+',
            'surname' => static fn (PersonalDataSheet $s) => $s->surname = null,
        ];

        foreach ($cases as $path => $break) {
            $sheet = $this->validSheet();
            $break($sheet);
            self::assertContains($path, $this->violationPaths($sheet), "Expected a violation on $path");
        }
    }
}
