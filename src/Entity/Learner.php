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

    private function syncName(): static
    {
        $this->user->setFullName($this->getFullName());

        return $this;
    }
}
