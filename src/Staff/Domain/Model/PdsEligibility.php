<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

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
    public ?string $name = null;

    #[ORM\Column(length: 20, nullable: true)]
    public ?string $rating = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $examDate = null;

    #[ORM\Column(length: 200, nullable: true)]
    public ?string $examPlace = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $licenseNo = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $licenseValidUntil = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
