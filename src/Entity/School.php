<?php

namespace App\Entity;

use App\Repository\SchoolRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: SchoolRepository::class)]
#[UniqueEntity(fields: ['schoolId'], message: 'This School ID already exists.')]
class School
{
    /** Validation group of the principal's School Profile form (only the fields it can edit). */
    public const PROFILE = 'school_profile';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    /** DepEd School ID, kept as text so leading zeros survive. */
    #[ORM\Column(length: 6, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{6}$/', message: 'School ID must be exactly 6 digits.')]
    private string $schoolId = '';

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    private string $name = '';

    #[ORM\Column(length: 20, enumType: SchoolType::class)]
    #[Assert\NotNull]
    private ?SchoolType $type = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $region = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    private string $division = '';

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100)]
    private ?string $district = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, groups: ['Default', self::PROFILE])]
    private ?string $address = null;

    #[ORM\Column]
    private bool $active = true;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Choose a plan.')]
    private ?Plan $plan = null;

    /** How many of the plan the school bought; the plan's limits are multiplied by it. */
    #[ORM\Column(options: ['default' => 1])]
    #[Assert\NotNull]
    #[Assert\Positive]
    private ?int $seats = 1;

    /** Last day the plan is paid for; null means it never expires. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $planValidUntil = null;

    /** School logo, relative to public/ (e.g. uploads/logos/ab12….webp). */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $logoPath = null;

    #[ORM\Column(length: 30, nullable: true)]
    #[Assert\Length(max: 30, groups: ['Default', self::PROFILE])]
    private ?string $contactNumber = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Assert\Email(groups: ['Default', self::PROFILE])]
    #[Assert\Length(max: 180, groups: ['Default', self::PROFILE])]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Url(requireTld: true, groups: ['Default', self::PROFILE])]
    #[Assert\Length(max: 255, groups: ['Default', self::PROFILE])]
    private ?string $facebookUrl = null;

    /** Who certifies the SF8 (the "Certified Correct" block). */
    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, groups: ['Default', self::PROFILE])]
    private ?string $sf8CertifierName = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, groups: ['Default', self::PROFILE])]
    private ?string $sf8CertifierPosition = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSchoolId(): string
    {
        return $this->schoolId;
    }

    public function setSchoolId(string $schoolId): static
    {
        $this->schoolId = trim($schoolId);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = trim($name);

        return $this;
    }

    public function getType(): ?SchoolType
    {
        return $this->type;
    }

    public function setType(?SchoolType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    /** Null (nothing chosen in the form) is kept as blank so NotBlank reports it. */
    public function setRegion(?string $region): static
    {
        $this->region = trim($region ?? '');

        return $this;
    }

    public function getDivision(): string
    {
        return $this->division;
    }

    public function setDivision(?string $division): static
    {
        $this->division = trim($division ?? '');

        return $this;
    }

    public function getDistrict(): ?string
    {
        return $this->district;
    }

    public function setDistrict(?string $district): static
    {
        $district = null !== $district ? trim($district) : null;
        $this->district = '' === $district ? null : $district;

        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $address = null !== $address ? trim($address) : null;
        $this->address = '' === $address ? null : $address;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function setActive(bool $active): static
    {
        $this->active = $active;

        return $this;
    }

    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    public function setPlan(?Plan $plan): static
    {
        $this->plan = $plan;

        return $this;
    }

    public function getSeats(): ?int
    {
        return $this->seats;
    }

    public function setSeats(?int $seats): static
    {
        $this->seats = $seats;

        return $this;
    }

    public function getPlanValidUntil(): ?\DateTimeImmutable
    {
        return $this->planValidUntil;
    }

    public function setPlanValidUntil(?\DateTimeImmutable $planValidUntil): static
    {
        $this->planValidUntil = $planValidUntil;

        return $this;
    }

    public function getLogoPath(): ?string
    {
        return $this->logoPath;
    }

    public function setLogoPath(?string $logoPath): static
    {
        $this->logoPath = $logoPath;

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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = self::blankToNull($email);

        return $this;
    }

    public function getFacebookUrl(): ?string
    {
        return $this->facebookUrl;
    }

    public function setFacebookUrl(?string $facebookUrl): static
    {
        $this->facebookUrl = self::blankToNull($facebookUrl);

        return $this;
    }

    public function getSf8CertifierName(): ?string
    {
        return $this->sf8CertifierName;
    }

    public function setSf8CertifierName(?string $name): static
    {
        $this->sf8CertifierName = self::blankToNull($name);

        return $this;
    }

    public function getSf8CertifierPosition(): ?string
    {
        return $this->sf8CertifierPosition;
    }

    public function setSf8CertifierPosition(?string $position): static
    {
        $this->sf8CertifierPosition = self::blankToNull($position);

        return $this;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = null !== $value ? trim($value) : null;

        return '' === $value ? null : $value;
    }

    public function getTeacherLimit(): int
    {
        return (int) $this->plan?->getTeachersPerSeat() * (int) $this->seats;
    }

    public function getStudentLimit(): int
    {
        return (int) $this->plan?->getStudentsPerSeat() * (int) $this->seats;
    }

    /** The plan is still valid on its last day. */
    public function isPlanExpired(\DateTimeImmutable $today): bool
    {
        return null !== $this->planValidUntil && $this->planValidUntil->format('Y-m-d') < $today->format('Y-m-d');
    }

    /** "Basic", or "Individual × 2" when more than one seat was bought. */
    public function getPlanLabel(): string
    {
        $name = $this->plan?->getName() ?? '—';

        return $this->seats > 1 ? \sprintf('%s × %d', $name, $this->seats) : $name;
    }
}
