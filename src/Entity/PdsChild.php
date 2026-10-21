<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/** PDS item 23: one child of the PDS owner. */
#[ORM\Entity]
class PdsChild
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?PersonalDataSheet $sheet = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Enter the child\'s full name.', groups: ['Default', PersonalDataSheet::FAMILY])]
    #[Assert\Length(max: 150, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $fullName = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    #[Assert\LessThanOrEqual('today', message: 'Date of birth cannot be in the future.', groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?\DateTimeImmutable $dateOfBirth = null;

    #[ORM\Column]
    public int $position = 0;

    public function getId(): ?int
    {
        return $this->id;
    }
}
