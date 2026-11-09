<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Staff\Domain\Model\ServiceRecordEntry;
use Symfony\Component\Validator\Constraints as Assert;

/** A row of an employee's service record as the principal adds or edits it; no entryId means a new row. */
final class SaveServiceRowCommand implements UseCase
{
    public int $actorId = 0;

    public int $schoolId = 0;

    public int $employeeId = 0;

    public ?int $entryId = null;

    #[Assert\NotNull]
    public ?\DateTimeImmutable $fromDate = null;

    public ?\DateTimeImmutable $toDate = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public ?string $designation = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 50)]
    public ?string $status = null;

    #[Assert\NotBlank]
    #[Assert\PositiveOrZero]
    public ?string $annualSalary = null;

    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    public ?string $station = null;

    #[Assert\NotBlank]
    #[Assert\Choice(choices: ServiceRecordEntry::BRANCHES)]
    public ?string $branch = null;

    public ?\DateTimeImmutable $lwopFrom = null;

    public ?\DateTimeImmutable $lwopTo = null;

    public ?\DateTimeImmutable $separationDate = null;

    #[Assert\Length(max: 150)]
    public ?string $separationCause = null;

    #[Assert\Length(max: 150)]
    public ?string $remarks = null;

    /**
     * On saving, end the person's open row the day before this one starts. Validation then counts the open row
     * as already ended, when it starts before this one.
     */
    public bool $closeOpen = false;

    /** The command that edits $entry as it is saved. */
    public static function fromEntry(ServiceRecordEntry $entry, int $actorId, int $schoolId): self
    {
        $command = new self();
        $command->actorId = $actorId;
        $command->schoolId = $schoolId;
        $command->employeeId = (int) $entry->getEmployee()->getId();
        $command->entryId = $entry->getId();
        $command->fromDate = $entry->getFromDate();
        $command->toDate = $entry->getToDate();
        $command->designation = $entry->getDesignation();
        $command->status = $entry->getStatus();
        $command->annualSalary = $entry->getAnnualSalary();
        $command->station = $entry->getStation();
        $command->branch = $entry->getBranch();
        $command->lwopFrom = $entry->getLwopFrom();
        $command->lwopTo = $entry->getLwopTo();
        $command->separationDate = $entry->getSeparationDate();
        $command->separationCause = $entry->getSeparationCause();
        $command->remarks = $entry->getRemarks();

        return $command;
    }

    /** Writes the row's fields onto $entry. */
    public function applyTo(ServiceRecordEntry $entry): void
    {
        $entry->setFromDate($this->fromDate)
            ->setToDate($this->toDate)
            ->setDesignation($this->designation)
            ->setStatus($this->status)
            ->setAnnualSalary($this->annualSalary)
            ->setStation($this->station)
            ->setBranch($this->branch)
            ->setLwopFrom($this->lwopFrom)
            ->setLwopTo($this->lwopTo)
            ->setSeparationDate($this->separationDate)
            ->setSeparationCause($this->separationCause)
            ->setRemarks($this->remarks);
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'employeeId' => $this->employeeId,
            'entryId' => $this->entryId,
            'closeOpen' => $this->closeOpen,
        ];
    }
}
