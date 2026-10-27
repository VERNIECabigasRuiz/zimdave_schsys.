<?php

namespace App\Entity;

use App\Repository\TeachingLoadRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** A teacher assigned to teach one subject in one section for the school year. */
#[ORM\Entity(repositoryClass: TeachingLoadRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_load_section_subject', columns: ['section_id', 'subject_id'])]
class TeachingLoad
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Section $section;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Subject $subject;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $teacher;

    /** @var Collection<int, ScheduleEntry> */
    #[ORM\OneToMany(targetEntity: ScheduleEntry::class, mappedBy: 'load')]
    private Collection $entries;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(Section $section, Subject $subject, User $teacher)
    {
        $this->section = $section;
        $this->subject = $subject;
        $this->teacher = $teacher;
        $this->entries = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function getSubject(): Subject
    {
        return $this->subject;
    }

    public function getTeacher(): User
    {
        return $this->teacher;
    }

    public function setTeacher(User $teacher): static
    {
        $this->teacher = $teacher;

        return $this;
    }

    /** @return Collection<int, ScheduleEntry> */
    public function getEntries(): Collection
    {
        return $this->entries;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
