<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** PDS item 27: one civil service eligibility, board/bar or license. */
#[ORM\Entity]
class PdsEligibility
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'eligibilities')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?PersonalDataSheet $sheet = null;

    #[ORM\Column]
    public int $position = 0;

    #[ORM\Column(length: 200)]
    #[Assert\NotBlank(message: 'Enter the eligibility.', groups: PersonalDataSheet::LIST_GROUPS)]
    #[Assert\Length(max: 200, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $name = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Assert\Length(max: 20, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $rating = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\LessThanOrEqual('today', message: 'The date cannot be in the future.', groups: PersonalDataSheet::LIST_GROUPS)]
    public ?\DateTimeImmutable $examDate = null;

    #[ORM\Column(length: 200, nullable: true)]
    #[Assert\Length(max: 200, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $examPlace = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Assert\Length(max: 50, groups: PersonalDataSheet::LIST_GROUPS)]
    public ?string $licenseNo = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    public ?\DateTimeImmutable $licenseValidUntil = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
