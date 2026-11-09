<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Staff\Domain\Model\PdsTraining;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Validator\Constraints as Assert;

/** One learning and development intervention. */
final class PdsTrainingRow
{
    #[Assert\NotBlank(message: 'Enter the title of the training.', groups: PersonalDataSheet::LIST_GROUPS)]
    #[Assert\Length(max: 255, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $title = null;

    #[Assert\NotNull(message: 'Enter the start date.', groups: PersonalDataSheet::LIST_GROUPS)]
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

    /** Managerial / Supervisory / Technical / Foundation / … */
    #[Assert\Length(max: 50, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $type = null;

    /** Conducted / sponsored by. */
    #[Assert\Length(max: 255, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $sponsor = null;

    public static function fromEntity(PdsTraining $entity): self
    {
        $row = new self();
        $row->title = $entity->title;
        $row->dateFrom = $entity->dateFrom;
        $row->dateTo = $entity->dateTo;
        $row->hours = $entity->hours;
        $row->type = $entity->type;
        $row->sponsor = $entity->sponsor;

        return $row;
    }

    public function applyTo(PdsTraining $entity): void
    {
        $entity->title = $this->title;
        $entity->dateFrom = $this->dateFrom;
        $entity->dateTo = $this->dateTo;
        $entity->hours = $this->hours;
        $entity->type = $this->type;
        $entity->sponsor = $this->sponsor;
    }
}
