<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** One learner absent, late or cutting classes in a roll call. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_attendance_mark', columns: ['roll_call_id', 'enrollment_id'])]
class AttendanceMark
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'marks')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private RollCall $rollCall;

    /** Unenrolling a learner deletes their marks. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Enrollment $enrollment;

    #[ORM\Column(length: 10, enumType: LearnerMark::class)]
    private LearnerMark $mark;

    public function __construct(RollCall $rollCall, Enrollment $enrollment, LearnerMark $mark)
    {
        $this->rollCall = $rollCall;
        $this->enrollment = $enrollment;
        $this->mark = $mark;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRollCall(): RollCall
    {
        return $this->rollCall;
    }

    public function getEnrollment(): Enrollment
    {
        return $this->enrollment;
    }

    public function getMark(): LearnerMark
    {
        return $this->mark;
    }

    public function setMark(LearnerMark $mark): static
    {
        $this->mark = $mark;

        return $this;
    }
}
