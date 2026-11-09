<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Command;

use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\SexAtBirth;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The fields of the learner details form. The LRN must also be free (UniqueLrn, checked by the form).
 *
 * @phpstan-import-type LearnerData from \App\Learners\Application\Transformer\LearnerTransformer
 */
final class LearnerDetails
{
    /** The learner these details belong to, or null for a new one. */
    public ?int $learnerId = null;

    #[Assert\Regex(pattern: '/^\d{12}$/', message: 'The LRN must be exactly 12 digits.')]
    public string $lrn = '';

    #[Assert\NotBlank(message: 'Enter the last name.')]
    #[Assert\Length(max: 50)]
    public string $lastName = '';

    #[Assert\NotBlank(message: 'Enter the first name.')]
    #[Assert\Length(max: 50)]
    public string $firstName = '';

    #[Assert\Length(max: 50)]
    public ?string $middleName = null;

    #[Assert\Length(max: 10)]
    public ?string $extension = null;

    #[Assert\NotNull(message: 'Choose the sex.')]
    public ?SexAtBirth $sex = null;

    #[Assert\NotNull(message: 'Enter the birthdate.')]
    #[Assert\LessThan('today', message: 'Birthdate must be before today.')]
    public ?\DateTimeImmutable $birthDate = null;

    // SF1 (School Register) information; all optional

    #[Assert\Length(max: 50)]
    public ?string $motherTongue = null;

    #[Assert\Length(max: 50)]
    public ?string $ipGroup = null;

    #[Assert\Length(max: 50)]
    public ?string $religion = null;

    #[Assert\Length(max: 100)]
    public ?string $addressStreet = null;

    #[Assert\Length(max: 50)]
    public ?string $addressBarangay = null;

    #[Assert\Length(max: 50)]
    public ?string $addressCity = null;

    #[Assert\Length(max: 50)]
    public ?string $addressProvince = null;

    #[Assert\Length(max: 50)]
    public ?string $fatherLastName = null;

    #[Assert\Length(max: 50)]
    public ?string $fatherFirstName = null;

    #[Assert\Length(max: 50)]
    public ?string $fatherMiddleName = null;

    #[Assert\Length(max: 50)]
    public ?string $motherLastName = null;

    #[Assert\Length(max: 50)]
    public ?string $motherFirstName = null;

    #[Assert\Length(max: 50)]
    public ?string $motherMiddleName = null;

    #[Assert\Length(max: 100)]
    public ?string $guardianName = null;

    #[Assert\Length(max: 30)]
    public ?string $guardianRelationship = null;

    #[Assert\Length(max: 20)]
    public ?string $contactNumber = null;

    /** A new learner's empty form, with the LRN typed on the first step. */
    public static function forNew(string $lrn): self
    {
        $details = new self();
        $details->lrn = $lrn;

        return $details;
    }

    /** @param LearnerData $learner */
    public static function fromLearner(array $learner): self
    {
        $details = new self();
        $details->learnerId = $learner['id'];
        $details->lrn = $learner['lrn'];
        $details->lastName = $learner['lastName'];
        $details->firstName = $learner['firstName'];
        $details->middleName = $learner['middleName'];
        $details->extension = $learner['extension'];
        $details->sex = $learner['sex'];
        $details->birthDate = $learner['birthDate'];
        $details->motherTongue = $learner['motherTongue'];
        $details->ipGroup = $learner['ipGroup'];
        $details->religion = $learner['religion'];
        $details->addressStreet = $learner['addressStreet'];
        $details->addressBarangay = $learner['addressBarangay'];
        $details->addressCity = $learner['addressCity'];
        $details->addressProvince = $learner['addressProvince'];
        $details->fatherLastName = $learner['fatherLastName'];
        $details->fatherFirstName = $learner['fatherFirstName'];
        $details->fatherMiddleName = $learner['fatherMiddleName'];
        $details->motherLastName = $learner['motherLastName'];
        $details->motherFirstName = $learner['motherFirstName'];
        $details->motherMiddleName = $learner['motherMiddleName'];
        $details->guardianName = $learner['guardianName'];
        $details->guardianRelationship = $learner['guardianRelationship'];
        $details->contactNumber = $learner['contactNumber'];

        return $details;
    }

    /** The name these details give the learner. */
    public function fullName(): string
    {
        return Learner::fullNameOf($this->lastName, $this->firstName, $this->middleName, $this->extension);
    }

    /** Sets every field but the LRN on $learner. */
    public function applyTo(Learner $learner): void
    {
        $learner
            ->setLastName($this->lastName)
            ->setFirstName($this->firstName)
            ->setMiddleName($this->middleName)
            ->setExtension($this->extension)
            ->setSex($this->sex)
            ->setBirthDate($this->birthDate)
            ->setMotherTongue($this->motherTongue)
            ->setIpGroup($this->ipGroup)
            ->setReligion($this->religion)
            ->setAddressStreet($this->addressStreet)
            ->setAddressBarangay($this->addressBarangay)
            ->setAddressCity($this->addressCity)
            ->setAddressProvince($this->addressProvince)
            ->setFatherLastName($this->fatherLastName)
            ->setFatherFirstName($this->fatherFirstName)
            ->setFatherMiddleName($this->fatherMiddleName)
            ->setMotherLastName($this->motherLastName)
            ->setMotherFirstName($this->motherFirstName)
            ->setMotherMiddleName($this->motherMiddleName)
            ->setGuardianName($this->guardianName)
            ->setGuardianRelationship($this->guardianRelationship)
            ->setContactNumber($this->contactNumber);
    }

    /** @return array<string, mixed> */
    public function toLogArray(): array
    {
        return ['learnerId' => $this->learnerId, 'lrn' => $this->lrn];
    }
}
