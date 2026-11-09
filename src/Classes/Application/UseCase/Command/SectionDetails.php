<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

use App\Classes\Domain\Model\ShsTrack;
use App\Core\Application\Bus\UseCase;
use App\Schools\Domain\Model\GradeLevel;
use Symfony\Component\Validator\Constraints as Assert;

/** The fields of a class section form. */
abstract class SectionDetails implements UseCase
{
    #[Assert\NotNull(message: 'Choose the grade level.')]
    public ?GradeLevel $gradeLevel = null;

    #[Assert\NotBlank(message: 'Enter the section name.')]
    #[Assert\Length(max: 60)]
    public string $name = '';

    public ?ShsTrack $track = null;

    #[Assert\Length(max: 60)]
    public ?string $strand = null;

    public ?int $adviserId = null;

    public function __construct(public readonly int $schoolId)
    {
    }

    /** The section this form edits, or null for a new one. */
    public function sectionId(): ?int
    {
        return null;
    }

    public function toLogArray(): array
    {
        return [
            'schoolId' => $this->schoolId,
            'gradeLevel' => $this->gradeLevel?->value,
            'name' => $this->name,
            'adviserId' => $this->adviserId,
        ];
    }
}
