<?php

declare(strict_types=1);

namespace App\Billing\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

/**
 * What a school bought: how many teachers and students it may have per seat.
 */
#[ORM\Entity]
class Plan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50, unique: true)]
    private string $name = '';

    #[ORM\Column]
    private int $teachersPerSeat = 0;

    #[ORM\Column]
    private int $studentsPerSeat = 0;

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

    public function getTeachersPerSeat(): int
    {
        return $this->teachersPerSeat;
    }

    public function setTeachersPerSeat(int $teachersPerSeat): static
    {
        $this->teachersPerSeat = $teachersPerSeat;

        return $this;
    }

    public function getStudentsPerSeat(): int
    {
        return $this->studentsPerSeat;
    }

    public function setStudentsPerSeat(int $studentsPerSeat): static
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
