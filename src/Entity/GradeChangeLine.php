<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/** One learner's grade in a change request: what it was and what the teacher wants it to be. */
#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_grade_change_line', columns: ['request_id', 'enrollment_id'])]
class GradeChangeLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(name: 'request_id', nullable: false, onDelete: 'CASCADE')]
    private GradeChangeRequest $request;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Enrollment $enrollment;

    /** Null when the learner had no grade yet. */
    #[ORM\Column(type: 'smallint', nullable: true)]
    private ?int $fromValue;

    #[ORM\Column(type: 'smallint')]
    private int $toValue;

    public function __construct(GradeChangeRequest $request, Enrollment $enrollment, ?int $fromValue, int $toValue)
    {
        $this->request = $request;
        $this->enrollment = $enrollment;
        $this->fromValue = $fromValue;
        $this->toValue = $toValue;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequest(): GradeChangeRequest
    {
        return $this->request;
    }

    public function getEnrollment(): Enrollment
    {
        return $this->enrollment;
    }

    public function getFromValue(): ?int
    {
        return $this->fromValue;
    }

    public function getToValue(): int
    {
        return $this->toValue;
    }
}
