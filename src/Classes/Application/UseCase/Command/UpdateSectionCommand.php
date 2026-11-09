<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

/**
 * The school edits a section. Bound to the section form, prefilled from GetSectionFormCommand.
 *
 * @phpstan-import-type SectionData from \App\Classes\Application\Transformer\SectionTransformer
 */
final class UpdateSectionCommand extends SectionDetails
{
    public function __construct(int $schoolId, public readonly int $id)
    {
        parent::__construct($schoolId);
    }

    /** @param SectionData $section */
    public static function fromSection(int $schoolId, array $section): self
    {
        $command = new self($schoolId, (int) $section['id']);
        $command->gradeLevel = $section['gradeLevel'];
        $command->name = $section['name'];
        $command->track = $section['track'];
        $command->strand = $section['strand'];
        $command->adviserId = $section['adviser']['id'] ?? null;

        return $command;
    }

    public function sectionId(): int
    {
        return $this->id;
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id] + parent::toLogArray();
    }
}
