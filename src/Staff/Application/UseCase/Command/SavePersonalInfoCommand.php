<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Learners\Domain\Model\SexAtBirth;
use App\Staff\Domain\Model\Citizenship;
use App\Staff\Domain\Model\CivilStatus;
use App\Staff\Domain\Model\DualCitizenshipBy;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Validator\Constraints as Assert;

/** The Personal Information tab (I) as the person saves it. */
final class SavePersonalInfoCommand implements UseCase
{
    public int $actorId = 0;

    /** 1. */
    #[Assert\NotBlank(message: 'Enter your surname.')]
    #[Assert\Length(max: 80)]
    public ?string $surname = null;

    /** 2. */
    #[Assert\NotBlank(message: 'Enter your first name.')]
    #[Assert\Length(max: 80)]
    public ?string $firstName = null;

    #[Assert\Length(max: 10)]
    public ?string $nameExtension = null;

    #[Assert\Length(max: 80)]
    public ?string $middleName = null;

    /** 3. */
    #[Assert\NotNull(message: 'Enter your date of birth.')]
    #[Assert\LessThan('today', message: 'Date of birth must be in the past.')]
    public ?\DateTimeImmutable $dateOfBirth = null;

    /** 4. */
    #[Assert\Length(max: 150)]
    public ?string $placeOfBirth = null;

    /** 5. */
    #[Assert\NotNull(message: 'Choose your sex at birth.')]
    public ?SexAtBirth $sexAtBirth = null;

    /** 6. */
    #[Assert\NotNull(message: 'Choose your civil status.')]
    public ?CivilStatus $civilStatus = null;

    #[Assert\Length(max: 50)]
    #[Assert\Expression(
        '!this.civilStatusOtherMissing()',
        message: 'Specify your civil status.',
    )]
    public ?string $civilStatusOther = null;

    /** 7. Metres. */
    #[Assert\Range(min: 0.5, max: 2.5, notInRangeMessage: 'Height must be between {{ min }} and {{ max }} metres.')]
    public ?string $height = null;

    /** 8. Kilograms. */
    #[Assert\Range(min: 10, max: 300, notInRangeMessage: 'Weight must be between {{ min }} and {{ max }} kg.')]
    public ?string $weight = null;

    /** 9. */
    #[Assert\Choice(choices: PersonalDataSheet::BLOOD_TYPES, message: 'Choose a valid blood type.')]
    public ?string $bloodType = null;

    /** 10. */
    #[Assert\Length(max: 30)]
    public ?string $umidNo = null;

    /** 11. */
    #[Assert\Length(max: 30)]
    public ?string $pagibigNo = null;

    /** 12. */
    #[Assert\Length(max: 30)]
    public ?string $philhealthNo = null;

    /** 13. PhilSys Card Number. */
    #[Assert\Length(max: 30)]
    public ?string $philsysPcn = null;

    /** 14. */
    #[Assert\Length(max: 30)]
    public ?string $tin = null;

    /** 15. */
    #[Assert\Length(max: 30)]
    public ?string $agencyEmployeeNo = null;

    /** 16. */
    #[Assert\NotNull(message: 'Choose your citizenship.')]
    public ?Citizenship $citizenship = null;

    #[Assert\Expression(
        '!this.dualCitizenshipByMissing()',
        message: 'Choose by birth or by naturalization.',
    )]
    public ?DualCitizenshipBy $dualCitizenshipBy = null;

    #[Assert\Length(max: 80)]
    #[Assert\Expression(
        '!this.dualCitizenshipCountryMissing()',
        message: 'Enter the other country of citizenship.',
    )]
    public ?string $dualCitizenshipCountry = null;

    /** 17. Residential address. */
    #[Assert\Length(max: 50)]
    public ?string $resHouseNo = null;

    #[Assert\Length(max: 100)]
    public ?string $resStreet = null;

    #[Assert\Length(max: 100)]
    public ?string $resSubdivision = null;

    #[Assert\Length(max: 100)]
    public ?string $resBarangay = null;

    #[Assert\Length(max: 100)]
    public ?string $resCity = null;

    #[Assert\Length(max: 100)]
    public ?string $resProvince = null;

    #[Assert\Regex(pattern: '/^\d{4}$/', message: 'ZIP code must be 4 digits.')]
    public ?string $resZip = null;

    /** 18. Permanent address. */
    public bool $permSameAsResidential = false;

    #[Assert\Length(max: 50)]
    public ?string $permHouseNo = null;

    #[Assert\Length(max: 100)]
    public ?string $permStreet = null;

    #[Assert\Length(max: 100)]
    public ?string $permSubdivision = null;

    #[Assert\Length(max: 100)]
    public ?string $permBarangay = null;

    #[Assert\Length(max: 100)]
    public ?string $permCity = null;

    #[Assert\Length(max: 100)]
    public ?string $permProvince = null;

    #[Assert\Regex(pattern: '/^\d{4}$/', message: 'ZIP code must be 4 digits.')]
    public ?string $permZip = null;

    /** 19. */
    #[Assert\Length(max: 30)]
    public ?string $telephoneNo = null;

    /** 20. */
    #[Assert\Regex(pattern: '/^09\d{9}$/', message: 'Mobile number must look like 09171234567.')]
    public ?string $mobileNo = null;

    /** 21. */
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    public static function fromSheet(PersonalDataSheet $sheet, int $actorId): self
    {
        $command = new self();
        $command->actorId = $actorId;
        $command->surname = $sheet->surname;
        $command->firstName = $sheet->firstName;
        $command->nameExtension = $sheet->nameExtension;
        $command->middleName = $sheet->middleName;
        $command->dateOfBirth = $sheet->dateOfBirth;
        $command->placeOfBirth = $sheet->placeOfBirth;
        $command->sexAtBirth = $sheet->sexAtBirth;
        $command->civilStatus = $sheet->civilStatus;
        $command->civilStatusOther = $sheet->civilStatusOther;
        $command->height = $sheet->height;
        $command->weight = $sheet->weight;
        $command->bloodType = $sheet->bloodType;
        $command->umidNo = $sheet->umidNo;
        $command->pagibigNo = $sheet->pagibigNo;
        $command->philhealthNo = $sheet->philhealthNo;
        $command->philsysPcn = $sheet->philsysPcn;
        $command->tin = $sheet->tin;
        $command->agencyEmployeeNo = $sheet->agencyEmployeeNo;
        $command->citizenship = $sheet->citizenship;
        $command->dualCitizenshipBy = $sheet->dualCitizenshipBy;
        $command->dualCitizenshipCountry = $sheet->dualCitizenshipCountry;
        $command->resHouseNo = $sheet->resHouseNo;
        $command->resStreet = $sheet->resStreet;
        $command->resSubdivision = $sheet->resSubdivision;
        $command->resBarangay = $sheet->resBarangay;
        $command->resCity = $sheet->resCity;
        $command->resProvince = $sheet->resProvince;
        $command->resZip = $sheet->resZip;
        $command->permSameAsResidential = $sheet->permSameAsResidential;
        $command->permHouseNo = $sheet->permHouseNo;
        $command->permStreet = $sheet->permStreet;
        $command->permSubdivision = $sheet->permSubdivision;
        $command->permBarangay = $sheet->permBarangay;
        $command->permCity = $sheet->permCity;
        $command->permProvince = $sheet->permProvince;
        $command->permZip = $sheet->permZip;
        $command->telephoneNo = $sheet->telephoneNo;
        $command->mobileNo = $sheet->mobileNo;
        $command->email = $sheet->email;

        return $command;
    }

    /** Writes this tab's fields onto the sheet. */
    public function applyTo(PersonalDataSheet $sheet): void
    {
        $sheet->surname = $this->surname;
        $sheet->firstName = $this->firstName;
        $sheet->nameExtension = $this->nameExtension;
        $sheet->middleName = $this->middleName;
        $sheet->dateOfBirth = $this->dateOfBirth;
        $sheet->placeOfBirth = $this->placeOfBirth;
        $sheet->sexAtBirth = $this->sexAtBirth;
        $sheet->civilStatus = $this->civilStatus;
        $sheet->civilStatusOther = $this->civilStatusOther;
        $sheet->height = $this->height;
        $sheet->weight = $this->weight;
        $sheet->bloodType = $this->bloodType;
        $sheet->umidNo = $this->umidNo;
        $sheet->pagibigNo = $this->pagibigNo;
        $sheet->philhealthNo = $this->philhealthNo;
        $sheet->philsysPcn = $this->philsysPcn;
        $sheet->tin = $this->tin;
        $sheet->agencyEmployeeNo = $this->agencyEmployeeNo;
        $sheet->citizenship = $this->citizenship;
        $sheet->dualCitizenshipBy = $this->dualCitizenshipBy;
        $sheet->dualCitizenshipCountry = $this->dualCitizenshipCountry;
        $sheet->resHouseNo = $this->resHouseNo;
        $sheet->resStreet = $this->resStreet;
        $sheet->resSubdivision = $this->resSubdivision;
        $sheet->resBarangay = $this->resBarangay;
        $sheet->resCity = $this->resCity;
        $sheet->resProvince = $this->resProvince;
        $sheet->resZip = $this->resZip;
        $sheet->permSameAsResidential = $this->permSameAsResidential;
        $sheet->permHouseNo = $this->permHouseNo;
        $sheet->permStreet = $this->permStreet;
        $sheet->permSubdivision = $this->permSubdivision;
        $sheet->permBarangay = $this->permBarangay;
        $sheet->permCity = $this->permCity;
        $sheet->permProvince = $this->permProvince;
        $sheet->permZip = $this->permZip;
        $sheet->telephoneNo = $this->telephoneNo;
        $sheet->mobileNo = $this->mobileNo;
        $sheet->email = $this->email;
    }

    /** "Others" was chosen but not specified. */
    public function civilStatusOtherMissing(): bool
    {
        return CivilStatus::Others === $this->civilStatus && !$this->civilStatusOther;
    }

    public function dualCitizenshipByMissing(): bool
    {
        return Citizenship::Dual === $this->citizenship && null === $this->dualCitizenshipBy;
    }

    public function dualCitizenshipCountryMissing(): bool
    {
        return Citizenship::Dual === $this->citizenship && !$this->dualCitizenshipCountry;
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'tab' => 'personal'];
    }
}
