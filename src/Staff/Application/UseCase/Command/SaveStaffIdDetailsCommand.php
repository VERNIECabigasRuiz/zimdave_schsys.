<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Staff\Domain\Model\StaffIdDetails;
use Symfony\Component\Validator\Constraints as Assert;

/** What a teacher or principal saves for their ID that the PDS doesn't hold. */
final class SaveStaffIdDetailsCommand implements UseCase
{
    public int $actorId = 0;

    #[Assert\Length(max: 100)]
    public ?string $emergencyName = null;

    #[Assert\Length(max: 60)]
    public ?string $emergencyContact = null;

    #[Assert\Length(max: 30)]
    public ?string $gsisPolicyNo = null;

    /** The big name on the Big ID, e.g. "Sir Jun"; empty means Sir/Ma'am and the first name. */
    #[Assert\Length(max: 30)]
    public ?string $callName = null;

    public static function fromDetails(StaffIdDetails $details, int $actorId): self
    {
        $command = new self();
        $command->actorId = $actorId;
        $command->emergencyName = $details->getEmergencyName();
        $command->emergencyContact = $details->getEmergencyContact();
        $command->gsisPolicyNo = $details->getGsisPolicyNo();
        $command->callName = $details->getCallName();

        return $command;
    }

    /** Writes the details onto $details. */
    public function applyTo(StaffIdDetails $details): void
    {
        $details->setEmergencyName($this->emergencyName)
            ->setEmergencyContact($this->emergencyContact)
            ->setGsisPolicyNo($this->gsisPolicyNo)
            ->setCallName($this->callName);
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId];
    }
}
