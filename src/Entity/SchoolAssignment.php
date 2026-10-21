<?php

namespace App\Entity;

use App\Repository\SchoolAssignmentRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * A user working at a school during one school year (as principal for now, later also as teacher).
 */
#[ORM\Entity(repositoryClass: SchoolAssignmentRepository::class)]
#[ORM\UniqueConstraint(columns: ['user_id', 'school_year_id'])]
class SchoolAssignment
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private School $school,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private SchoolYear $schoolYear,
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private User $user,
        #[ORM\Column(length: 30)]
        private string $role,
    ) {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSchool(): School
    {
        return $this->school;
    }

    public function setSchool(School $school): static
    {
        $this->school = $school;

        return $this;
    }

    public function getSchoolYear(): SchoolYear
    {
        return $this->schoolYear;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
