<?php

namespace App\Entity;

use App\Repository\LearnerRepository;
use App\Validator\UniqueLrn;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** A child, kept for life across schools and school years; owns the login account. */
#[ORM\Entity(repositoryClass: LearnerRepository::class)]
#[UniqueLrn]
class Learner
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 12, unique: true)]
    #[Assert\Regex(pattern: '/^\d{12}$/', message: 'The LRN must be exactly 12 digits.')]
    private string $lrn = '';

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Enter the last name.')]
    #[Assert\Length(max: 50)]
    private string $lastName = '';

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Enter the first name.')]
    #[Assert\Length(max: 50)]
    private string $firstName = '';

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $middleName = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Length(max: 10)]
    private ?string $extension = null;

    #[ORM\Column(length: 10, enumType: SexAtBirth::class)]
    #[Assert\NotNull(message: 'Choose the sex.')]
    private ?SexAtBirth $sex = null;

    #[ORM\Column(type: 'date_immutable')]
    #[Assert\NotNull(message: 'Enter the birthdate.')]
    #[Assert\LessThan('today', message: 'Birthdate must be before today.')]
    private ?\DateTimeImmutable $birthDate = null;

    // SF1 (School Register) information; all optional

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $motherTongue = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $ipGroup = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $religion = null;

    /** House #, street, sitio or purok */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $addressStreet = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $addressBarangay = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $addressCity = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $addressProvince = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $fatherLastName = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $fatherFirstName = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $fatherMiddleName = null;

    /** The mother's maiden name, like the other two */
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $motherLastName = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $motherFirstName = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50)]
    private ?string $motherMiddleName = null;

    /** Only when the guardian is not a parent */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $guardianName = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30)]
    private ?string $guardianRelationship = null;

    /** The parent's or guardian's */
    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20)]
    private ?string $contactNumber = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'RESTRICT')]
    private User $user;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(User $user, \DateTimeImmutable $createdAt)
    {
        $this->user = $user;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getLrn(): string
    {
        return $this->lrn;
    }

    public function setLrn(?string $lrn): static
    {
        $this->lrn = trim((string) $lrn);
        $this->user->setUsername($this->lrn);

        return $this;
    }

    public function getLastName(): string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = trim((string) $lastName);

        return $this->syncName();
    }

    public function getFirstName(): string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = trim((string) $firstName);

        return $this->syncName();
    }

    public function getMiddleName(): ?string
    {
        return $this->middleName;
    }

    public function setMiddleName(?string $middleName): static
    {
        $middleName = null !== $middleName ? trim($middleName) : null;
        $this->middleName = '' === $middleName ? null : $middleName;

        return $this->syncName();
    }

    public function getExtension(): ?string
    {
        return $this->extension;
    }

    public function setExtension(?string $extension): static
    {
        $extension = null !== $extension ? trim($extension) : null;
        $this->extension = '' === $extension ? null : $extension;

        return $this->syncName();
    }

    public function getSex(): ?SexAtBirth
    {
        return $this->sex;
    }

    public function setSex(?SexAtBirth $sex): static
    {
        $this->sex = $sex;

        return $this;
    }

    public function getBirthDate(): ?\DateTimeImmutable
    {
        return $this->birthDate;
    }

    public function setBirthDate(?\DateTimeImmutable $birthDate): static
    {
        $this->birthDate = $birthDate;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** "Dela Cruz, Juan Santos Jr." */
    public function getFullName(): string
    {
        $given = implode(' ', array_filter([$this->firstName, $this->middleName, $this->extension]));

        return '' === $this->lastName ? $given : ('' === $given ? $this->lastName : $this->lastName.', '.$given);
    }

    public function getMotherTongue(): ?string
    {
        return $this->motherTongue;
    }

    public function setMotherTongue(?string $motherTongue): static
    {
        $this->motherTongue = self::blankToNull($motherTongue);

        return $this;
    }

    public function getIpGroup(): ?string
    {
        return $this->ipGroup;
    }

    public function setIpGroup(?string $ipGroup): static
    {
        $this->ipGroup = self::blankToNull($ipGroup);

        return $this;
    }

    public function getReligion(): ?string
    {
        return $this->religion;
    }

    public function setReligion(?string $religion): static
    {
        $this->religion = self::blankToNull($religion);

        return $this;
    }

    public function getAddressStreet(): ?string
    {
        return $this->addressStreet;
    }

    public function setAddressStreet(?string $addressStreet): static
    {
        $this->addressStreet = self::blankToNull($addressStreet);

        return $this;
    }

    public function getAddressBarangay(): ?string
    {
        return $this->addressBarangay;
    }

    public function setAddressBarangay(?string $addressBarangay): static
    {
        $this->addressBarangay = self::blankToNull($addressBarangay);

        return $this;
    }

    public function getAddressCity(): ?string
    {
        return $this->addressCity;
    }

    public function setAddressCity(?string $addressCity): static
    {
        $this->addressCity = self::blankToNull($addressCity);

        return $this;
    }

    public function getAddressProvince(): ?string
    {
        return $this->addressProvince;
    }

    public function setAddressProvince(?string $addressProvince): static
    {
        $this->addressProvince = self::blankToNull($addressProvince);

        return $this;
    }

    public function getFatherLastName(): ?string
    {
        return $this->fatherLastName;
    }

    public function setFatherLastName(?string $fatherLastName): static
    {
        $this->fatherLastName = self::blankToNull($fatherLastName);

        return $this;
    }

    public function getFatherFirstName(): ?string
    {
        return $this->fatherFirstName;
    }

    public function setFatherFirstName(?string $fatherFirstName): static
    {
        $this->fatherFirstName = self::blankToNull($fatherFirstName);

        return $this;
    }

    public function getFatherMiddleName(): ?string
    {
        return $this->fatherMiddleName;
    }

    public function setFatherMiddleName(?string $fatherMiddleName): static
    {
        $this->fatherMiddleName = self::blankToNull($fatherMiddleName);

        return $this;
    }

    public function getMotherLastName(): ?string
    {
        return $this->motherLastName;
    }

    public function setMotherLastName(?string $motherLastName): static
    {
        $this->motherLastName = self::blankToNull($motherLastName);

        return $this;
    }

    public function getMotherFirstName(): ?string
    {
        return $this->motherFirstName;
    }

    public function setMotherFirstName(?string $motherFirstName): static
    {
        $this->motherFirstName = self::blankToNull($motherFirstName);

        return $this;
    }

    public function getMotherMiddleName(): ?string
    {
        return $this->motherMiddleName;
    }

    public function setMotherMiddleName(?string $motherMiddleName): static
    {
        $this->motherMiddleName = self::blankToNull($motherMiddleName);

        return $this;
    }

    public function getGuardianName(): ?string
    {
        return $this->guardianName;
    }

    public function setGuardianName(?string $guardianName): static
    {
        $this->guardianName = self::blankToNull($guardianName);

        return $this;
    }

    public function getGuardianRelationship(): ?string
    {
        return $this->guardianRelationship;
    }

    public function setGuardianRelationship(?string $guardianRelationship): static
    {
        $this->guardianRelationship = self::blankToNull($guardianRelationship);

        return $this;
    }

    public function getContactNumber(): ?string
    {
        return $this->contactNumber;
    }

    public function setContactNumber(?string $contactNumber): static
    {
        $this->contactNumber = self::blankToNull($contactNumber);

        return $this;
    }

    /** The name as SF1 prints it: "BAÑEZ, REMUEL JR, BRAVO"; "-" for no middle name. */
    public function getSf1Name(): string
    {
        $first = implode(' ', array_filter([$this->firstName, $this->extension]));

        return mb_strtoupper($this->lastName.', '.$first.', '.($this->middleName ?? '-'));
    }

    /** "ARNOL, GERALD ABUHAT", or null when no name was given */
    public function getFatherSf1Name(): ?string
    {
        return self::parentName($this->fatherLastName, $this->fatherFirstName, $this->fatherMiddleName);
    }

    /** The mother's maiden name, as getFatherSf1Name() */
    public function getMotherSf1Name(): ?string
    {
        return self::parentName($this->motherLastName, $this->motherFirstName, $this->motherMiddleName);
    }

    /** Age in whole years on the first Friday of June of $year, the date SF1 counts from. */
    public function getAgeOnFirstFridayOfJune(int $year): ?int
    {
        return $this->birthDate?->diff(new \DateTimeImmutable('first friday of june '.$year))->y;
    }

    /** Missing the address, a parent or guardian, or the contact number. */
    public function isSf1Incomplete(): bool
    {
        $hasParent = (null !== $this->fatherLastName && null !== $this->fatherFirstName)
            || (null !== $this->motherLastName && null !== $this->motherFirstName);

        return null === $this->addressBarangay || null === $this->addressCity || null === $this->addressProvince
            || (!$hasParent && null === $this->guardianName)
            || null === $this->contactNumber;
    }

    private static function parentName(?string $last, ?string $first, ?string $middle): ?string
    {
        if (null === $last && null === $first) {
            return null;
        }
        $given = implode(' ', array_filter([$first, $middle]));

        return mb_strtoupper(null === $last ? $given : ('' === $given ? $last : $last.', '.$given));
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = null !== $value ? trim($value) : null;

        return '' === $value ? null : $value;
    }

    private function syncName(): static
    {
        $this->user->setFullName($this->getFullName());

        return $this;
    }
}
