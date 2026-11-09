<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Staff\Domain\Model\PdsVoluntaryWork;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Validator\Constraints as Assert;

/** One voluntary work. */
final class PdsVoluntaryWorkRow
{
    /** Name & address of the organization. */
    #[Assert\NotBlank(
        message: 'Enter the name and address of the organization.',
        groups: PersonalDataSheet::LIST_GROUPS,
    )]
    #[Assert\Length(max: 255, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $organization = null;

    public ?\DateTimeImmutable $dateFrom = null;

    #[Assert\GreaterThanOrEqual(
        propertyPath: 'dateFrom',
        message: 'The end date cannot be before the start date.',
        groups: PersonalDataSheet::LIST_GROUPS,
    )]
    public ?\DateTimeImmutable $dateTo = null;

    #[Assert\Regex(
        pattern: '/^\d{1,4}(\.\d{1,2})?$/',
        message: 'Enter the number of hours, e.g. 24 or 7.5.',
        groups: PersonalDataSheet::LIST_GROUPS,
    )]
    #[Assert\Positive(message: 'Hours must be more than 0.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $hours = null;

    /** Position / nature of work. */
    #[Assert\Length(max: 150, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $nature = null;

    public static function fromEntity(PdsVoluntaryWork $entity): self
    {
        $row = new self();
        $row->organization = $entity->organization;
        $row->dateFrom = $entity->dateFrom;
        $row->dateTo = $entity->dateTo;
        $row->hours = $entity->hours;
        $row->nature = $entity->nature;

        return $row;
    }

    public function applyTo(PdsVoluntaryWork $entity): void
    {
        $entity->organization = $this->organization;
        $entity->dateFrom = $this->dateFrom;
        $entity->dateTo = $this->dateTo;
        $entity->hours = $this->hours;
        $entity->nature = $this->nature;
    }
}
