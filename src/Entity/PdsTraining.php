<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** PDS item 29: one learning and development intervention / training program. */
#[ORM\Entity]
class PdsTraining
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'trainings')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?PersonalDataSheet $sheet = null;

    #[ORM\Column]
    public int $position = 0;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Enter the title of the training.', groups: PersonalDataSheet::LIST_GROUPS)]
    #[Assert\Length(max: 255, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $title = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    #[Assert\NotNull(message: 'Enter the start date.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?\DateTimeImmutable $dateFrom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'dateFrom', message: 'The end date cannot be before the start date.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?\DateTimeImmutable $dateTo = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    #[Assert\Regex(pattern: '/^\d{1,4}(\.\d{1,2})?$/', message: 'Enter the number of hours, e.g. 24 or 7.5.', groups: PersonalDataSheet::LIST_GROUPS)]
    #[Assert\Positive(message: 'Hours must be more than 0.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $hours = null;

    /** Managerial / Supervisory / Technical / Foundation / … */
    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $type = null;

    /** Conducted / sponsored by. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $sponsor = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
