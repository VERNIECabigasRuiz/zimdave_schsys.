<?php

namespace App\Entity;

use App\Repository\LessonPlanRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A week's ILAW lesson plan (Daily Lesson Log – ILAW format) for one teaching load. The days hold one entry per
 * meeting day: its date, a holiday reason when the school is closed, and the ILAW rows, one bullet per line.
 */
#[ORM\Entity(repositoryClass: LessonPlanRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_lesson_plan_load_week', columns: ['load_id', 'week_start'])]
class LessonPlan
{
    /** The ILAW rows that have a cell per day, in the order they print. */
    public const DAY_FIELDS = ['objectives', 'learnerContext', 'preLesson', 'flow', 'resources', 'integration', 'formativeAssessment', 'extendedLearning', 'reflections'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TeachingLoad $load;

    /** The Monday of the week. */
    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $weekStart;

    #[ORM\Column(length: 200)]
    #[Assert\Length(max: 200)]
    private string $topic = '';

    #[ORM\Column(type: Types::TEXT)]
    private string $competencies = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $classNotes = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $references = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $aiDeclaration = null;

    /** @var list<array<string, string|null>> */
    #[ORM\Column(type: Types::JSON)]
    private array $days = [];

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $draftedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(TeachingLoad $load, \DateTimeImmutable $weekStart, \DateTimeImmutable $now)
    {
        $this->load = $load;
        $this->weekStart = $weekStart->setTime(0, 0);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /** @return array<string, string|null> a day with every ILAW cell empty */
    public static function emptyDay(string $date, ?string $holiday = null): array
    {
        return ['date' => $date, 'holiday' => $holiday] + array_fill_keys(self::DAY_FIELDS, '');
    }

    /** @return list<string> the non-empty lines of a cell, trimmed: what prints as bullets */
    public static function bullets(?string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $text) ?: []), static fn (string $line) => '' !== $line));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLoad(): TeachingLoad
    {
        return $this->load;
    }

    public function getWeekStart(): \DateTimeImmutable
    {
        return $this->weekStart;
    }

    public function getTopic(): string
    {
        return $this->topic;
    }

    public function setTopic(?string $topic): static
    {
        $this->topic = trim((string) $topic);

        return $this;
    }

    public function getCompetencies(): string
    {
        return $this->competencies;
    }

    public function setCompetencies(?string $competencies): static
    {
        $this->competencies = trim((string) $competencies);

        return $this;
    }

    public function getClassNotes(): ?string
    {
        return $this->classNotes;
    }

    public function setClassNotes(?string $classNotes): static
    {
        $this->classNotes = self::blankToNull($classNotes);

        return $this;
    }

    public function getReferences(): ?string
    {
        return $this->references;
    }

    public function setReferences(?string $references): static
    {
        $this->references = self::blankToNull($references);

        return $this;
    }

    public function getAiDeclaration(): ?string
    {
        return $this->aiDeclaration;
    }

    public function setAiDeclaration(?string $aiDeclaration): static
    {
        $this->aiDeclaration = self::blankToNull($aiDeclaration);

        return $this;
    }

    /** @return list<array<string, string|null>> */
    public function getDays(): array
    {
        return $this->days;
    }

    /** @param list<array<string, string|null>> $days */
    public function setDays(array $days): static
    {
        $this->days = array_values($days);

        return $this;
    }

    /** Meeting days the school is open. */
    public function sessionCount(): int
    {
        return \count(array_filter($this->days, static fn (array $day) => null === ($day['holiday'] ?? null)));
    }

    public function getDraftedAt(): ?\DateTimeImmutable
    {
        return $this->draftedAt;
    }

    public function setDraftedAt(?\DateTimeImmutable $draftedAt): static
    {
        $this->draftedAt = $draftedAt;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(\DateTimeImmutable $at): static
    {
        $this->updatedAt = $at;

        return $this;
    }

    private static function blankToNull(?string $value): ?string
    {
        $value = null === $value ? null : trim($value);

        return '' === $value ? null : $value;
    }
}
