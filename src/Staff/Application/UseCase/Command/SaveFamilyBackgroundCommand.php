<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Staff\Domain\Model\PdsChild;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Validator\Constraints as Assert;

/** The Family Background tab (II) as the person saves it. */
final class SaveFamilyBackgroundCommand implements UseCase
{
    public int $actorId = 0;

    /** 22. Spouse. */
    #[Assert\Length(max: 80, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $spouseSurname = null;

    #[Assert\Length(max: 80, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $spouseFirstName = null;

    #[Assert\Length(max: 10, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $spouseNameExtension = null;

    #[Assert\Length(max: 80, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $spouseMiddleName = null;

    #[Assert\Length(max: 100, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $spouseOccupation = null;

    #[Assert\Length(max: 150, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $spouseEmployer = null;

    #[Assert\Length(max: 255, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $spouseBusinessAddress = null;

    #[Assert\Length(max: 30, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $spouseTelephone = null;

    /** 24. Father. */
    #[Assert\Length(max: 80, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $fatherSurname = null;

    #[Assert\Length(max: 80, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $fatherFirstName = null;

    #[Assert\Length(max: 10, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $fatherNameExtension = null;

    #[Assert\Length(max: 80, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $fatherMiddleName = null;

    /** 25. Mother's maiden name. */
    #[Assert\Length(max: 80, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $motherMaidenSurname = null;

    #[Assert\Length(max: 80, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $motherFirstName = null;

    #[Assert\Length(max: 80, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $motherMiddleName = null;

    /**
     * 23. Keyed as on the form.
     *
     * @var array<int, PdsChildRow>
     */
    #[Assert\Valid(groups: [PersonalDataSheet::FAMILY])]
    #[Assert\Count(max: 30, maxMessage: 'List at most {{ limit }} children.', groups: [PersonalDataSheet::FAMILY])]
    public array $children = [];

    public static function fromSheet(PersonalDataSheet $sheet, int $actorId): self
    {
        $command = new self();
        $command->actorId = $actorId;
        $command->spouseSurname = $sheet->spouseSurname;
        $command->spouseFirstName = $sheet->spouseFirstName;
        $command->spouseNameExtension = $sheet->spouseNameExtension;
        $command->spouseMiddleName = $sheet->spouseMiddleName;
        $command->spouseOccupation = $sheet->spouseOccupation;
        $command->spouseEmployer = $sheet->spouseEmployer;
        $command->spouseBusinessAddress = $sheet->spouseBusinessAddress;
        $command->spouseTelephone = $sheet->spouseTelephone;
        $command->fatherSurname = $sheet->fatherSurname;
        $command->fatherFirstName = $sheet->fatherFirstName;
        $command->fatherNameExtension = $sheet->fatherNameExtension;
        $command->fatherMiddleName = $sheet->fatherMiddleName;
        $command->motherMaidenSurname = $sheet->motherMaidenSurname;
        $command->motherFirstName = $sheet->motherFirstName;
        $command->motherMiddleName = $sheet->motherMiddleName;
        foreach (array_values($sheet->getChildren()->toArray()) as $i => $row) {
            $command->children[$i] = PdsChildRow::fromEntity($row);
        }

        return $command;
    }

    /** Writes this tab's fields onto the sheet. */
    public function applyTo(PersonalDataSheet $sheet): void
    {
        $sheet->spouseSurname = $this->spouseSurname;
        $sheet->spouseFirstName = $this->spouseFirstName;
        $sheet->spouseNameExtension = $this->spouseNameExtension;
        $sheet->spouseMiddleName = $this->spouseMiddleName;
        $sheet->spouseOccupation = $this->spouseOccupation;
        $sheet->spouseEmployer = $this->spouseEmployer;
        $sheet->spouseBusinessAddress = $this->spouseBusinessAddress;
        $sheet->spouseTelephone = $this->spouseTelephone;
        $sheet->fatherSurname = $this->fatherSurname;
        $sheet->fatherFirstName = $this->fatherFirstName;
        $sheet->fatherNameExtension = $this->fatherNameExtension;
        $sheet->fatherMiddleName = $this->fatherMiddleName;
        $sheet->motherMaidenSurname = $this->motherMaidenSurname;
        $sheet->motherFirstName = $this->motherFirstName;
        $sheet->motherMiddleName = $this->motherMiddleName;
        $existing = array_values($sheet->getChildren()->toArray());
        foreach ($existing as $i => $row) {
            if (!isset($this->children[$i])) {
                $sheet->removeChild($row);
            }
        }
        foreach ($this->children as $i => $input) {
            $row = $existing[$i] ?? new PdsChild();
            $input->applyTo($row);
            $sheet->addChild($row);
        }
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'tab' => 'family', 'children' => \count($this->children)];
    }
}
