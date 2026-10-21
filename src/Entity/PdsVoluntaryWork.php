<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** PDS item 30: voluntary work or involvement in a civic / non-government / people / voluntary organization. */
#[ORM\Entity]
class PdsVoluntaryWork
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'voluntaryWorks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?PersonalDataSheet $sheet = null;

    #[ORM\Column]
    public int $position = 0;

    /** Name & address of the organization. */
    #[ORM\Column(length: 255)]
    #[Assert\NotBlank(message: 'Enter the name and address of the organization.', groups: PersonalDataSheet::LIST_GROUPS)]
    #[Assert\Length(max: 255, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $organization = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $dateFrom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\GreaterThanOrEqual(propertyPath: 'dateFrom', message: 'The end date cannot be before the start date.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?\DateTimeImmutable $dateTo = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 6, scale: 2, nullable: true)]
    #[Assert\Regex(pattern: '/^\d{1,4}(\.\d{1,2})?$/', message: 'Enter the number of hours, e.g. 24 or 7.5.', groups: PersonalDataSheet::LIST_GROUPS)]
    #[Assert\Positive(message: 'Hours must be more than 0.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $hours = null;

    /** Position / nature of work. */
    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\Length(max: 150, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $nature = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
