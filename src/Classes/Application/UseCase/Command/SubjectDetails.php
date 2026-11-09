<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

use App\Classes\Application\Service\Subject\SubjectScopeKey;
use App\Classes\Domain\Model\ShsTrack;
use App\Core\Application\Bus\UseCase;
use App\Schools\Domain\Model\GradeLevel;
use Symfony\Component\Validator\Constraints as Assert;

/** The fields of a subject form. */
abstract class SubjectDetails implements UseCase
{
    public const SEMESTERS = ['1st semester' => 1, '2nd semester' => 2];

    #[Assert\NotNull(message: 'Choose the grade level.')]
    public ?GradeLevel $gradeLevel = null;

    #[Assert\NotBlank(message: 'Enter the subject code.')]
    #[Assert\Length(max: 20)]
    #[Assert\Regex(pattern: '/^[A-Z0-9-]*$/', message: 'Use only letters, numbers and dashes in the code.')]
    public string $code = '';

    #[Assert\NotBlank(message: 'Enter the subject name.')]
    #[Assert\Length(max: 100)]
    public string $name = '';

    public ?int $parentId = null;

    /** Report-card order within the grade (and semester). */
    #[Assert\NotNull]
    #[Assert\Positive]
    public ?int $position = 1;

    /** How many class meetings a week; null on a subject with components. */
    #[Assert\Range(min: 1, max: 10)]
    public ?int $meetingsPerWeek = null;

    #[Assert\Choice(choices: [1, 2])]
    public ?int $semester = null;

    public ?ShsTrack $track = null;

    #[Assert\Length(max: 60)]
    public ?string $strand = null;

    /** Whether the edited subject has components (a new one has none). */
    public bool $hasComponents = false;

    /** @var list<GradeLevel> the grade levels of the edited subject's components */
    public array $componentGrades = [];

    public function __construct(public readonly SubjectScopeKey $scope)
    {
    }

    /** The subject this form edits, or null for a new one. */
    public function subjectId(): ?int
    {
        return null;
    }

    public function toLogArray(): array
    {
        return $this->scope->toLogArray() + ['code' => $this->code, 'name' => $this->name];
    }
}
