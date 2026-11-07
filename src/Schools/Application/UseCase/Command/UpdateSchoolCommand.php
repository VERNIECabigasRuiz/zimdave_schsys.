<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

/**
 * An admin edits a school. Bound to the school form, prefilled from GetSchoolFormCommand.
 *
 * @phpstan-import-type SchoolData from \App\Schools\Application\Transformer\SchoolTransformer
 */
final class UpdateSchoolCommand extends SchoolDetails
{
    public function __construct(public readonly int $id)
    {
    }

    /** @param SchoolData $school */
    public static function fromSchool(array $school): self
    {
        $command = new self($school['id']);
        $command->schoolId = $school['schoolId'];
        $command->name = $school['name'];
        $command->type = $school['type'];
        $command->region = $school['region'];
        $command->division = $school['division'];
        $command->district = $school['district'];
        $command->address = $school['address'];
        $command->planId = $school['planId'];
        $command->seats = $school['seats'];
        $command->planValidUntil = $school['planValidUntil'];

        return $command;
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id] + parent::toLogArray();
    }
}
