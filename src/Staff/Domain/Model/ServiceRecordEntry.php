<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model;

use App\Access\Domain\Model\User;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row of an employee's service record (the DepEd form "To Be Accomplished By Employer"). It belongs to the
 * person, not to a school, so it stays when they transfer. No end date means the row runs to the present.
 */
#[ORM\Entity]
class ServiceRecordEntry
{
    public const STATUSES = ['Regular/Permanent', 'Provisional', 'Substitute', 'Contractual', 'Casual', 'Temporary'];
    public const BRANCHES = ['National', 'Local'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $employee;

    #[ORM\Column(type: 'date_immutable')]
    private ?\DateTimeImmutable $fromDate;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $toDate = null;

    #[ORM\Column(length: 100)]
    private ?string $designation = null;

    #[ORM\Column(length: 50)]
    private ?string $status = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 2)]
    private ?string $annualSalary = null;

    #[ORM\Column(length: 150)]
    private ?string $station = null;

    #[ORM\Column(length: 20)]
    private ?string $branch = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lwopFrom = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $lwopTo = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    private ?\DateTimeImmutable $separationDate = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $separationCause = null;

    #[ORM\Column(length: 150, nullable: true)]
    private ?string $remarks = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $updatedBy;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        User $employee,
        \DateTimeImmutable $fromDate,
        User $updatedBy,
        \DateTimeImmutable $updatedAt,
    ) {
        $this->employee = $employee;
        $this->fromDate = $fromDate->setTime(0, 0);
        $this->updatedBy = $updatedBy;
        $this->updatedAt = $updatedAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmployee(): User
    {
        return $this->employee;
    }

    public function getFromDate(): ?\DateTimeImmutable
    {
        return $this->fromDate;
    }

    public function setFromDate(?\DateTimeImmutable $fromDate): static
    {
        $this->fromDate = $fromDate?->setTime(0, 0);

        return $this;
    }

    public function getToDate(): ?\DateTimeImmutable
    {
        return $this->toDate;
    }

    public function setToDate(?\DateTimeImmutable $toDate): static
    {
        $this->toDate = $toDate?->setTime(0, 0);

        return $this;
    }

    public function isOpen(): bool
    {
        return null === $this->toDate;
    }

    public function getDesignation(): ?string
    {
        return $this->designation;
    }

    public function setDesignation(?string $designation): static
    {
        $this->designation = self::blankToNull($designation);

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): static
    {
        $this->status = self::blankToNull($status);

        return $this;
    }

    public function getAnnualSalary(): ?string
    {
        return $this->annualSalary;
    }

    public function setAnnualSalary(string|float|null $annualSalary): static
    {
        $this->annualSalary = null === $annualSalary ? null : number_format((float) $annualSalary, 2, '.', '');

        return $this;
    }

    public function getStation(): ?string
    {
        return $this->station;
    }

    public function setStation(?string $station): static
    {
        $this->station = self::blankToNull($station);

        return $this;
    }

    public function getBranch(): ?string
    {
        return $this->branch;
    }

    public function setBranch(?string $branch): static
    {
        $this->branch = self::blankToNull($branch);

        return $this;
    }

    public function getLwopFrom(): ?\DateTimeImmutable
    {
        return $this->lwopFrom;
    }

    public function setLwopFrom(?\DateTimeImmutable $lwopFrom): static
    {
        $this->lwopFrom = $lwopFrom?->setTime(0, 0);

        return $this;
    }

    public function getLwopTo(): ?\DateTimeImmutable
    {
        return $this->lwopTo;
    }

    public function setLwopTo(?\DateTimeImmutable $lwopTo): static
    {
        $this->lwopTo = $lwopTo?->setTime(0, 0);

        return $this;
    }

    public function getSeparationDate(): ?\DateTimeImmutable
    {
        return $this->separationDate;
    }

    public function setSeparationDate(?\DateTimeImmutable $separationDate): static
    {
        $this->separationDate = $separationDate?->setTime(0, 0);

        return $this;
    }

    public function getSeparationCause(): ?string
    {
        return $this->separationCause;
    }

    public function setSeparationCause(?string $separationCause): static
    {
        $this->separationCause = self::blankToNull($separationCause);

        return $this;
    }

    public function getRemarks(): ?string
    {
        return $this->remarks;
    }

    public function setRemarks(?string $remarks): static
    {
        $this->remarks = self::blankToNull($remarks);

        return $this;
    }

    public function getUpdatedBy(): ?User
    {
        return $this->updatedBy;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(User $by, \DateTimeImmutable $at): static
    {
        $this->updatedBy = $by;
        $this->updatedAt = $at;

        return $this;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
