<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Staff\Domain\Model\PdsEligibility;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Validator\Constraints as Assert;

/** One civil service eligibility. */
final class PdsEligibilityRow
{
    #[Assert\NotBlank(message: 'Enter the eligibility.', groups: PersonalDataSheet::LIST_GROUPS)]
    #[Assert\Length(max: 200, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $name = null;

    #[Assert\Length(max: 20, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $rating = null;

    #[Assert\LessThanOrEqual(
        'today',
        message: 'The date cannot be in the future.',
        groups: PersonalDataSheet::LIST_GROUPS,
    )]
    public ?\DateTimeImmutable $examDate = null;

    #[Assert\Length(max: 200, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $examPlace = null;

    #[Assert\Length(max: 50, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $licenseNo = null;

    public ?\DateTimeImmutable $licenseValidUntil = null;

    public static function fromEntity(PdsEligibility $entity): self
    {
        $row = new self();
        $row->name = $entity->name;
        $row->rating = $entity->rating;
        $row->examDate = $entity->examDate;
        $row->examPlace = $entity->examPlace;
        $row->licenseNo = $entity->licenseNo;
        $row->licenseValidUntil = $entity->licenseValidUntil;

        return $row;
    }

    public function applyTo(PdsEligibility $entity): void
    {
        $entity->name = $this->name;
        $entity->rating = $this->rating;
        $entity->examDate = $this->examDate;
        $entity->examPlace = $this->examPlace;
        $entity->licenseNo = $this->licenseNo;
        $entity->licenseValidUntil = $this->licenseValidUntil;
    }
}
