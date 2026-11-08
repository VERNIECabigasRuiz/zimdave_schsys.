<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Staff\Domain\Model\PdsReference;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Validator\Constraints as Assert;

/** The References tab (41) and the government-issued ID as the person saves them. */
final class SaveReferencesCommand implements UseCase
{
    public int $actorId = 0;

    #[Assert\Length(max: 80, groups: [PersonalDataSheet::REFERENCES])]
    public ?string $govIdType = null;

    #[Assert\Length(max: 80, groups: [PersonalDataSheet::REFERENCES])]
    public ?string $govIdNumber = null;

    /** Date / place of issuance, free text as on the form. */
    #[Assert\Length(max: 150, groups: [PersonalDataSheet::REFERENCES])]
    public ?string $govIdIssued = null;

    /**
     * 41. Keyed as on the form; the form always shows three.
     *
     * @var array<int, PdsReferenceRow>
     */
    #[Assert\Valid(groups: [PersonalDataSheet::REFERENCES])]
    #[Assert\Expression(
        '!this.partlyListed()',
        message: 'List three references, or leave all three empty for now.',
        groups: [PersonalDataSheet::REFERENCES],
    )]
    public array $references = [];

    public static function fromSheet(PersonalDataSheet $sheet, int $actorId): self
    {
        $command = new self();
        $command->actorId = $actorId;
        $command->govIdType = $sheet->govIdType;
        $command->govIdNumber = $sheet->govIdNumber;
        $command->govIdIssued = $sheet->govIdIssued;
        foreach (array_values($sheet->getReferences()->toArray()) as $i => $row) {
            $command->references[$i] = PdsReferenceRow::fromEntity($row);
        }
        while (\count($command->references) < 3) {
            $command->references[] = new PdsReferenceRow();
        }

        return $command;
    }

    /** Writes this tab's fields onto the sheet. */
    public function applyTo(PersonalDataSheet $sheet): void
    {
        $sheet->govIdType = $this->govIdType;
        $sheet->govIdNumber = $this->govIdNumber;
        $sheet->govIdIssued = $this->govIdIssued;
        $existing = array_values($sheet->getReferences()->toArray());
        foreach ($existing as $i => $row) {
            if (!isset($this->references[$i])) {
                $sheet->removeReference($row);
            }
        }
        foreach ($this->references as $i => $input) {
            $row = $existing[$i] ?? new PdsReference();
            $input->applyTo($row);
            $sheet->addReference($row);
        }
    }

    /** Some references are listed, but fewer than three. */
    public function partlyListed(): bool
    {
        $count = \count(array_filter($this->references, static fn (PdsReferenceRow $r) => !$r->isEmpty()));

        return $count > 0 && $count < 3;
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'tab' => 'references', 'references' => \count($this->references)];
    }
}
