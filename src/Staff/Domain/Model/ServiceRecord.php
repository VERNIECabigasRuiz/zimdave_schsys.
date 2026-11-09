<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model;

use App\Access\Domain\Model\User;
use Doctrine\ORM\Mapping as ORM;

/** The parts of an employee's service record that aren't rows and aren't in the PDS: the GSIS BP number. */
#[ORM\Entity]
class ServiceRecord
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $employee;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $bpNumber = null;

    public function __construct(User $employee)
    {
        $this->employee = $employee;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmployee(): User
    {
        return $this->employee;
    }

    public function getBpNumber(): ?string
    {
        return $this->bpNumber;
    }

    public function setBpNumber(?string $bpNumber): static
    {
        $bpNumber = null === $bpNumber ? null : trim($bpNumber);
        $this->bpNumber = '' === $bpNumber ? null : $bpNumber;

        return $this;
    }
}
