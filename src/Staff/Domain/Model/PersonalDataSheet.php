<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model;

use App\Learners\Domain\Model\SexAtBirth;
use App\Access\Domain\Model\User;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Personal Data Sheet, CS Form No. 212 (Revised 2026). One per account.
 *
 * The form's fields are public properties named after the form items; comments
 * give the item number.
 */
#[ORM\Entity]
class PersonalDataSheet
{
    /** Validation group of the Family Background form, which must not require section I. */
    public const FAMILY = 'family';

    /** Validation group of the list tabs (III–VIII), which must not require section I. */
    public const LISTS = 'lists';
    public const LIST_GROUPS = ['Default', self::LISTS];

    /** Validation groups of the "Questions 34–40" and "References & ID" tabs. */
    public const QUESTIONS = 'questions';
    public const REFERENCES = 'references';

    /**
     * Each question: its Yes/No answer field(s), then the fields required when an answer is Yes.
     * 34a and 34b share one "If YES, give details" box.
     */
    public const QUESTION_DETAILS = [
        '34' => [['q34a', 'q34b'], ['q34Details']],
        '35a' => [['q35a'], ['q35aDetails']],
        '35b' => [['q35b'], ['q35bDetails', 'q35bDateFiled', 'q35bStatus']],
        '36' => [['q36'], ['q36Details']],
        '37' => [['q37'], ['q37Details']],
        '38a' => [['q38a'], ['q38aDetails']],
        '38b' => [['q38b'], ['q38bDetails']],
        '39' => [['q39'], ['q39Details']],
        '40a' => [['q40a'], ['q40aDetails']],
        '40b' => [['q40b'], ['q40bDetails']],
        '40c' => [['q40c'], ['q40cDetails']],
    ];

    /** Rows a list tab accepts (checked by its form, so one list never blocks another tab). */
    public const MAX_ROWS = 100;

    public const BLOOD_TYPES = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'];

    private const ADDRESS_PARTS = ['HouseNo', 'Street', 'Subdivision', 'Barangay', 'City', 'Province', 'Zip'];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false, unique: true, onDelete: 'CASCADE')]
    private User $user;

    // I. PERSONAL INFORMATION

    /** 1. */
    #[ORM\Column(length: 80, nullable: true)]
    public ?string $surname = null;

    /** 2. */
    #[ORM\Column(length: 80, nullable: true)]
    public ?string $firstName = null;

    #[ORM\Column(length: 10, nullable: true)]
    public ?string $nameExtension = null;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $middleName = null;

    /** 3. */
    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $dateOfBirth = null;

    /** 4. */
    #[ORM\Column(length: 150, nullable: true)]
    public ?string $placeOfBirth = null;

    /** 5. */
    #[ORM\Column(length: 10, nullable: true, enumType: SexAtBirth::class)]
    public ?SexAtBirth $sexAtBirth = null;

    /** 6. */
    #[ORM\Column(length: 10, nullable: true, enumType: CivilStatus::class)]
    public ?CivilStatus $civilStatus = null;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $civilStatusOther = null;

    /** 7. Metres. */
    #[ORM\Column(type: 'decimal', precision: 4, scale: 2, nullable: true)]
    public ?string $height = null;

    /** 8. Kilograms. */
    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    public ?string $weight = null;

    /** 9. */
    #[ORM\Column(length: 5, nullable: true)]
    public ?string $bloodType = null;

    /** 10. */
    #[ORM\Column(length: 30, nullable: true)]
    public ?string $umidNo = null;

    /** 11. */
    #[ORM\Column(length: 30, nullable: true)]
    public ?string $pagibigNo = null;

    /** 12. */
    #[ORM\Column(length: 30, nullable: true)]
    public ?string $philhealthNo = null;

    /** 13. PhilSys Card Number. */
    #[ORM\Column(length: 30, nullable: true)]
    public ?string $philsysPcn = null;

    /** 14. */
    #[ORM\Column(length: 30, nullable: true)]
    public ?string $tin = null;

    /** 15. */
    #[ORM\Column(length: 30, nullable: true)]
    public ?string $agencyEmployeeNo = null;

    /** 16. */
    #[ORM\Column(length: 10, nullable: true, enumType: Citizenship::class)]
    public ?Citizenship $citizenship = null;

    #[ORM\Column(length: 20, nullable: true, enumType: DualCitizenshipBy::class)]
    public ?DualCitizenshipBy $dualCitizenshipBy = null;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $dualCitizenshipCountry = null;

    /** 17. Residential address. */
    #[ORM\Column(length: 50, nullable: true)]
    public ?string $resHouseNo = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $resStreet = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $resSubdivision = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $resBarangay = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $resCity = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $resProvince = null;

    #[ORM\Column(length: 4, nullable: true)]
    public ?string $resZip = null;

    /** 18. Permanent address. */
    #[ORM\Column]
    public bool $permSameAsResidential = false;

    #[ORM\Column(length: 50, nullable: true)]
    public ?string $permHouseNo = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $permStreet = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $permSubdivision = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $permBarangay = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $permCity = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $permProvince = null;

    #[ORM\Column(length: 4, nullable: true)]
    public ?string $permZip = null;

    /** 19. */
    #[ORM\Column(length: 30, nullable: true)]
    public ?string $telephoneNo = null;

    /** 20. */
    #[ORM\Column(length: 20, nullable: true)]
    public ?string $mobileNo = null;

    /** 21. */
    #[ORM\Column(length: 180, nullable: true)]
    public ?string $email = null;

    // II. FAMILY BACKGROUND

    /** 22. Spouse. */
    #[ORM\Column(length: 80, nullable: true)]
    public ?string $spouseSurname = null;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $spouseFirstName = null;

    #[ORM\Column(length: 10, nullable: true)]
    public ?string $spouseNameExtension = null;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $spouseMiddleName = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $spouseOccupation = null;

    #[ORM\Column(length: 150, nullable: true)]
    public ?string $spouseEmployer = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $spouseBusinessAddress = null;

    #[ORM\Column(length: 30, nullable: true)]
    public ?string $spouseTelephone = null;

    /** 24. Father. */
    #[ORM\Column(length: 80, nullable: true)]
    public ?string $fatherSurname = null;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $fatherFirstName = null;

    #[ORM\Column(length: 10, nullable: true)]
    public ?string $fatherNameExtension = null;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $fatherMiddleName = null;

    /** 25. Mother's maiden name. */
    #[ORM\Column(length: 80, nullable: true)]
    public ?string $motherMaidenSurname = null;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $motherFirstName = null;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $motherMiddleName = null;

    /**
     * 23.
     *
     * @var Collection<int, PdsChild>
     */
    #[ORM\OneToMany(targetEntity: PdsChild::class, mappedBy: 'sheet', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $children;

    // III–VIII. Lists, each saved by its own My Profile tab

    /**
     * 26.
     *
     * @var Collection<int, PdsEducation>
     */
    #[ORM\OneToMany(targetEntity: PdsEducation::class, mappedBy: 'sheet', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $educations;

    /**
     * 27.
     *
     * @var Collection<int, PdsEligibility>
     */
    #[ORM\OneToMany(targetEntity: PdsEligibility::class, mappedBy: 'sheet', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $eligibilities;

    /**
     * 28.
     *
     * @var Collection<int, PdsWorkExperience>
     */
    #[ORM\OneToMany(
        targetEntity: PdsWorkExperience::class,
        mappedBy: 'sheet',
        cascade: ['persist'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $workExperiences;

    /**
     * 29.
     *
     * @var Collection<int, PdsTraining>
     */
    #[ORM\OneToMany(targetEntity: PdsTraining::class, mappedBy: 'sheet', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $trainings;

    /**
     * 30.
     *
     * @var Collection<int, PdsVoluntaryWork>
     */
    #[ORM\OneToMany(
        targetEntity: PdsVoluntaryWork::class,
        mappedBy: 'sheet',
        cascade: ['persist'],
        orphanRemoval: true,
    )]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $voluntaryWorks;

    /**
     * 31. Special skills and hobbies.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    public array $skills = [];

    /**
     * 32. Non-academic distinctions / recognition.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    public array $distinctions = [];

    /**
     * 33. Membership in association / organization.
     *
     * @var list<string>
     */
    #[ORM\Column(type: 'json')]
    public array $memberships = [];

    // 34–40. Yes/No questions: null = not answered yet. Details are only kept for a Yes.

    #[ORM\Column(nullable: true)]
    public ?bool $q34a = null;

    #[ORM\Column(nullable: true)]
    public ?bool $q34b = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $q34Details = null;

    #[ORM\Column(nullable: true)]
    public ?bool $q35a = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $q35aDetails = null;

    #[ORM\Column(nullable: true)]
    public ?bool $q35b = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $q35bDetails = null;

    #[ORM\Column(type: 'date_immutable', nullable: true)]
    public ?\DateTimeImmutable $q35bDateFiled = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $q35bStatus = null;

    #[ORM\Column(nullable: true)]
    public ?bool $q36 = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $q36Details = null;

    #[ORM\Column(nullable: true)]
    public ?bool $q37 = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $q37Details = null;

    #[ORM\Column(nullable: true)]
    public ?bool $q38a = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $q38aDetails = null;

    #[ORM\Column(nullable: true)]
    public ?bool $q38b = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $q38bDetails = null;

    #[ORM\Column(nullable: true)]
    public ?bool $q39 = null;

    /** Country. */
    #[ORM\Column(length: 100, nullable: true)]
    public ?string $q39Details = null;

    #[ORM\Column(nullable: true)]
    public ?bool $q40a = null;

    /** Indigenous group. */
    #[ORM\Column(length: 150, nullable: true)]
    public ?string $q40aDetails = null;

    #[ORM\Column(nullable: true)]
    public ?bool $q40b = null;

    /** PWD ID no. */
    #[ORM\Column(length: 50, nullable: true)]
    public ?string $q40bDetails = null;

    #[ORM\Column(nullable: true)]
    public ?bool $q40c = null;

    /** Solo parent ID no. */
    #[ORM\Column(length: 50, nullable: true)]
    public ?string $q40cDetails = null;

    // 41. References and the government-issued ID

    /** @var Collection<int, PdsReference> */
    #[ORM\OneToMany(targetEntity: PdsReference::class, mappedBy: 'sheet', cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $references;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $govIdType = null;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $govIdNumber = null;

    /** Date / place of issuance, free text as on the form. */
    #[ORM\Column(length: 150, nullable: true)]
    public ?string $govIdIssued = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct(User $user)
    {
        $this->user = $user;
        $this->children = new ArrayCollection();
        $this->educations = new ArrayCollection();
        $this->eligibilities = new ArrayCollection();
        $this->workExperiences = new ArrayCollection();
        $this->trainings = new ArrayCollection();
        $this->voluntaryWorks = new ArrayCollection();
        $this->references = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    /** @return Collection<int, PdsChild> */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(PdsChild $child): static
    {
        if (!$this->children->contains($child)) {
            $child->sheet = $this;
            $child->position = $this->children->count();
            $this->children->add($child);
        }

        return $this;
    }

    public function removeChild(PdsChild $child): static
    {
        $this->children->removeElement($child);

        return $this;
    }

    /** Renumbers children in their current order (after the form added/removed rows). */
    public function renumberChildren(): void
    {
        foreach (array_values($this->children->toArray()) as $i => $child) {
            $child->position = $i;
        }
    }

    /** @return Collection<int, PdsEducation> */
    public function getEducations(): Collection
    {
        return $this->educations;
    }

    public function addEducation(PdsEducation $row): static
    {
        return $this->addRow($this->educations, $row);
    }

    public function removeEducation(PdsEducation $row): static
    {
        $this->educations->removeElement($row);

        return $this;
    }

    /** @return Collection<int, PdsEligibility> */
    public function getEligibilities(): Collection
    {
        return $this->eligibilities;
    }

    public function addEligibility(PdsEligibility $row): static
    {
        return $this->addRow($this->eligibilities, $row);
    }

    public function removeEligibility(PdsEligibility $row): static
    {
        $this->eligibilities->removeElement($row);

        return $this;
    }

    /** @return Collection<int, PdsWorkExperience> */
    public function getWorkExperiences(): Collection
    {
        return $this->workExperiences;
    }

    public function addWorkExperience(PdsWorkExperience $row): static
    {
        return $this->addRow($this->workExperiences, $row);
    }

    public function removeWorkExperience(PdsWorkExperience $row): static
    {
        $this->workExperiences->removeElement($row);

        return $this;
    }

    /** @return Collection<int, PdsTraining> */
    public function getTrainings(): Collection
    {
        return $this->trainings;
    }

    public function addTraining(PdsTraining $row): static
    {
        return $this->addRow($this->trainings, $row);
    }

    public function removeTraining(PdsTraining $row): static
    {
        $this->trainings->removeElement($row);

        return $this;
    }

    /** @return Collection<int, PdsVoluntaryWork> */
    public function getVoluntaryWorks(): Collection
    {
        return $this->voluntaryWorks;
    }

    public function addVoluntaryWork(PdsVoluntaryWork $row): static
    {
        return $this->addRow($this->voluntaryWorks, $row);
    }

    public function removeVoluntaryWork(PdsVoluntaryWork $row): static
    {
        $this->voluntaryWorks->removeElement($row);

        return $this;
    }

    /** @return Collection<int, PdsReference> */
    public function getReferences(): Collection
    {
        return $this->references;
    }

    public function addReference(PdsReference $row): static
    {
        return $this->addRow($this->references, $row);
    }

    public function removeReference(PdsReference $row): static
    {
        $this->references->removeElement($row);

        return $this;
    }

    /** Drops reference rows left completely blank on the form. */
    public function removeEmptyReferences(): void
    {
        foreach ($this->references->toArray() as $reference) {
            if ($reference->isEmpty()) {
                $this->references->removeElement($reference);
            }
        }
    }

    /** Removes "If YES" details of questions that are no longer answered Yes. */
    public function clearUnusedAnswerDetails(): void
    {
        foreach (self::QUESTION_DETAILS as [$answers, $details]) {
            if (!$this->anyYes($answers)) {
                foreach ($details as $field) {
                    $this->{$field} = null;
                }
            }
        }
    }

    /** @param list<string> $answers */
    private function anyYes(array $answers): bool
    {
        foreach ($answers as $answer) {
            if (true === $this->{$answer}) {
                return true;
            }
        }

        return false;
    }

    /**
     * @template T of PdsChild|PdsEducation|PdsEligibility|PdsReference|PdsTraining|PdsVoluntaryWork|PdsWorkExperience
     *
     * @param Collection<int, T> $rows
     * @param T                  $row
     */
    private function addRow(Collection $rows, object $row): static
    {
        if (!$rows->contains($row)) {
            $row->sheet = $this;
            $row->position = $rows->count();
            $rows->add($row);
        }

        return $this;
    }

    /**
     * 26. By level as the form lists them, then by start year.
     *
     * @return list<PdsEducation>
     */
    public function sortedEducations(): array
    {
        return self::sorted(
            $this->educations,
            static fn (PdsEducation $a, PdsEducation $b)
                => [$a->level?->order(), $a->yearFrom ?? 0] <=> [$b->level?->order(), $b->yearFrom ?? 0],
        );
    }

    /**
     * 27. In the order the user entered them.
     *
     * @return list<PdsEligibility>
     */
    public function sortedEligibilities(): array
    {
        return self::sorted(
            $this->eligibilities,
            static fn (PdsEligibility $a, PdsEligibility $b) => $a->position <=> $b->position,
        );
    }

    /**
     * 28. "Start from your recent work": current job first, then newest start date.
     *
     * @return list<PdsWorkExperience>
     */
    public function sortedWorkExperiences(): array
    {
        return self::sorted(
            $this->workExperiences,
            static fn (PdsWorkExperience $a, PdsWorkExperience $b)
                => [$b->present, $b->dateFrom] <=> [$a->present, $a->dateFrom],
        );
    }

    /**
     * 29. Newest first.
     *
     * @return list<PdsTraining>
     */
    public function sortedTrainings(): array
    {
        return self::sorted(
            $this->trainings,
            static fn (PdsTraining $a, PdsTraining $b) => $b->dateFrom <=> $a->dateFrom,
        );
    }

    /**
     * 30. Newest first, undated last.
     *
     * @return list<PdsVoluntaryWork>
     */
    public function sortedVoluntaryWorks(): array
    {
        return self::sorted(
            $this->voluntaryWorks,
            static fn (PdsVoluntaryWork $a, PdsVoluntaryWork $b)
                => [null === $a->dateFrom, $b->dateFrom] <=> [null === $b->dateFrom, $a->dateFrom],
        );
    }

    /** Stores the display order in each row's position, so the tabs and the PDF agree. */
    public function renumberLists(): void
    {
        foreach ($this->workExperiences as $job) {
            if ($job->present) {
                $job->dateTo = null;
            }
        }

        $lists = [
            $this->sortedEducations(),
            $this->sortedEligibilities(),
            $this->sortedWorkExperiences(),
            $this->sortedTrainings(),
            $this->sortedVoluntaryWorks(),
            array_values($this->references->toArray()),
        ];
        foreach ($lists as $rows) {
            foreach ($rows as $i => $row) {
                $row->position = $i;
            }
        }
    }

    public function trainingHours(): float
    {
        return array_sum(array_map(static fn (PdsTraining $t) => (float) $t->hours, $this->trainings->toArray()));
    }

    /**
     * @template T of object
     *
     * @param Collection<int, T>  $rows
     * @param callable(T, T): int $compare
     *
     * @return list<T>
     */
    private static function sorted(Collection $rows, callable $compare): array
    {
        $list = array_values($rows->toArray());
        usort($list, $compare);

        return $list;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    /** "Juan A. Dela Cruz Jr.", or null until surname and first name are filled. */
    public function displayName(): ?string
    {
        if (!$this->surname || !$this->firstName) {
            return null;
        }
        $parts = [$this->firstName];
        if ($this->middleName) {
            $parts[] = mb_strtoupper(mb_substr($this->middleName, 0, 1)) . '.';
        }
        $parts[] = $this->surname;
        if ($this->nameExtension) {
            $parts[] = $this->nameExtension;
        }

        return implode(' ', $parts);
    }

    public function syncPermanentAddress(): void
    {
        if (!$this->permSameAsResidential) {
            return;
        }
        foreach (self::ADDRESS_PARTS as $part) {
            $this->{'perm' . $part} = $this->{'res' . $part};
        }
    }
}
