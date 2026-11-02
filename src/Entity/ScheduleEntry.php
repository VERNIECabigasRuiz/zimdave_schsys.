<?php

namespace App\Entity;

use App\Repository\ScheduleEntryRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One meeting of a teaching load on a weekday and class period. Section, teacher,
 * school year and term are copied from the load so the database can enforce that
 * neither a section nor a teacher is in two places at once.
 * Term: 0 = full year, 1 or 2 = semester.
 */
#[ORM\Entity(repositoryClass: ScheduleEntryRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_entry_section_slot', columns: ['section_id', 'day', 'period_id', 'term'])]
#[ORM\UniqueConstraint(name: 'uniq_entry_teacher_slot', columns: ['teacher_id', 'school_year_id', 'day', 'period_id', 'term'])]
class ScheduleEntry
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'entries')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private TeachingLoad $load;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private Section $section;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private User $teacher;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private SchoolYear $schoolYear;

    #[ORM\Column(type: 'smallint', enumType: Weekday::class)]
    private Weekday $day;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'RESTRICT')]
    private ClassPeriod $period;

    #[ORM\Column(type: 'smallint')]
    private int $term;

    public function __construct(TeachingLoad $load, Weekday $day, ClassPeriod $period)
    {
        $this->load = $load;
        $this->section = $load->getSection();
        $this->teacher = $load->getTeacher();
        $this->schoolYear = $load->getSection()->getSchoolYear();
        $this->term = $load->getSubject()->getSemester() ?? 0;
        $this->day = $day;
        $this->period = $period;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLoad(): TeachingLoad
    {
        return $this->load;
    }

    public function getSection(): Section
    {
        return $this->section;
    }

    public function getTeacher(): User
    {
        return $this->teacher;
    }

    public function getSchoolYear(): SchoolYear
    {
        return $this->schoolYear;
    }

    public function getDay(): Weekday
    {
        return $this->day;
    }

    public function getPeriod(): ClassPeriod
    {
        return $this->period;
    }

    public function getTerm(): int
    {
        return $this->term;
    }

    /** Re-copies the teacher after the load's teacher changed. */
    public function syncTeacher(): void
    {
        $this->teacher = $this->load->getTeacher();
    }
}
