<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

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
    public ?string $title = null;

    #[ORM\Column(type: 'date_immutable')]
    public ?\DateTimeImmutable $dateFrom = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $dateTo = null;

    #[ORM\Column(type: 'decimal', precision: 6, scale: 2, nullable: true)]
    public ?string $hours = null;

    /** Managerial / Supervisory / Technical / Foundation / … */
    #[ORM\Column(length: 50, nullable: true)]
    public ?string $type = null;

    /** Conducted / sponsored by. */
    #[ORM\Column(length: 255, nullable: true)]
    public ?string $sponsor = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
