<?php

namespace App\Entity;

use App\Repository\PlanRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * What a school bought: how many teachers and students it may have per seat.
 */
#[ORM\Entity(repositoryClass: PlanRepository::class)]
#[UniqueEntity(fields: ['name'], message: 'This plan already exists.')]
class Plan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    private string $name = '';

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\Positive]
    private ?int $teachersPerSeat = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\Positive]
    private ?int $studentsPerSeat = null;

    #[ORM\Column]
    private bool $includesPrincipal = true;

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTeachersPerSeat(): ?int
    {
        return $this->teachersPerSeat;
    }

    public function setTeachersPerSeat(?int $teachersPerSeat): static
    {
        $this->teachersPerSeat = $teachersPerSeat;

        return $this;
    }

    public function getStudentsPerSeat(): ?int
    {
        return $this->studentsPerSeat;
    }

    public function setStudentsPerSeat(?int $studentsPerSeat): static
    {
        $this->studentsPerSeat = $studentsPerSeat;

        return $this;
    }

    public function includesPrincipal(): bool
    {
        return $this->includesPrincipal;
    }

    public function setIncludesPrincipal(bool $includesPrincipal): static
    {
        $this->includesPrincipal = $includesPrincipal;

        return $this;
    }
}
