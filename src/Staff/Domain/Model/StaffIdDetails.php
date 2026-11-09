<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model;

use App\Access\Domain\Model\User;
use Doctrine\ORM\Mapping as ORM;

/** What a staff ID needs that the PDS doesn't hold: the emergency contact and the GSIS policy number. */
#[ORM\Entity]
class StaffIdDetails
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $emergencyName = null;

    #[ORM\Column(length: 60, nullable: true)]
    private ?string $emergencyContact = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $gsisPolicyNo = null;

    /** The big name on the Big ID, e.g. "Sir Jun"; empty means Sir/Ma'am and the first name. */
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $callName = null;

    public function __construct(User $user)
    {
        $this->user = $user;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getEmergencyName(): ?string
    {
        return $this->emergencyName;
    }

    public function setEmergencyName(?string $emergencyName): static
    {
        $this->emergencyName = self::blankToNull($emergencyName);

        return $this;
    }

    public function getEmergencyContact(): ?string
    {
        return $this->emergencyContact;
    }

    public function setEmergencyContact(?string $emergencyContact): static
    {
        $this->emergencyContact = self::blankToNull($emergencyContact);

        return $this;
    }

    public function getGsisPolicyNo(): ?string
    {
        return $this->gsisPolicyNo;
    }

    public function setGsisPolicyNo(?string $gsisPolicyNo): static
    {
        $this->gsisPolicyNo = self::blankToNull($gsisPolicyNo);

        return $this;
    }

    public function getCallName(): ?string
    {
        return $this->callName;
    }

    public function setCallName(?string $callName): static
    {
        $this->callName = self::blankToNull($callName);

        return $this;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
