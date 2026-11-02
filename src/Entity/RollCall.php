<?php

namespace App\Entity;

use App\Repository\RollCallRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * A section's attendance for one school day. Only the learners who weren't simply present
 * have a mark; a roll call with no marks means everyone was present.
 */
#[ORM\Entity(repositoryClass: RollCallRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_roll_call_section_date', columns: ['section_id', 'date'])]
class RollCall
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Section $section;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $takenBy;

    #[ORM\Column]
    private \DateTimeImmutable $takenAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    /** @var Collection<int, AttendanceMark> */
    #[ORM\OneToMany(targetEntity: AttendanceMark::class, mappedBy: 'rollCall', cascade: ['persist'], orphanRemoval: true)]
    private Collection $marks;

    public function __construct(Section $section, \DateTimeImmutable $date, User $takenBy, \DateTimeImmutable $takenAt)
    {
        $this->section = $section;
        $this->date = $date->setTime(0, 0);
        $this->takenBy = $takenBy;
        $this->takenAt = $takenAt;
        $this->marks = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function getTakenBy(): User
    {
        return $this->takenBy;
    }

    public function getTakenAt(): \DateTimeImmutable
    {
        return $this->takenAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(\DateTimeImmutable $at): static
    {
        $this->updatedAt = $at;

        return $this;
    }

    /** @return Collection<int, AttendanceMark> */
    public function getMarks(): Collection
    {
        return $this->marks;
    }

    public function markFor(Enrollment $enrollment): ?LearnerMark
    {
        foreach ($this->marks as $mark) {
            if ($mark->getEnrollment() === $enrollment || (null !== $enrollment->getId() && $mark->getEnrollment()->getId() === $enrollment->getId())) {
                return $mark->getMark();
            }
        }

        return null;
    }

    /**
     * Makes the marks exactly $marks: learners left out are present again.
     *
     * @param array<int, array{0: Enrollment, 1: LearnerMark}> $marks keyed by enrollment id
     */
    public function replaceMarks(array $marks): void
    {
        foreach ($this->marks->toArray() as $mark) {
            $id = $mark->getEnrollment()->getId();
            if (!isset($marks[$id])) {
                $this->marks->removeElement($mark);
                continue;
            }
            $mark->setMark($marks[$id][1]);
            unset($marks[$id]);
        }
        foreach ($marks as [$enrollment, $learnerMark]) {
            $this->marks->add(new AttendanceMark($this, $enrollment, $learnerMark));
        }
    }
}
