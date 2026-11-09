<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

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
    public ?string $organization = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $dateFrom = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $dateTo = null;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    public ?string $hours = null;

    /** Position / nature of work. */
    #[ORM\Column(length: 150, nullable: true)]
    public ?string $nature = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
