<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

/**
 * An admin edits a school year and its grading period dates. Bound to the school year form,
 * prefilled from GetSchoolYearFormCommand.
 *
 * @phpstan-import-type SchoolYearData from \App\Schools\Application\Transformer\SchoolYearTransformer
 */
final class UpdateSchoolYearCommand extends SchoolYearDetails
{
    /** @var array<int, GradingPeriodInput> */
    public array $gradingPeriods = [];

    public function __construct(public readonly int $id)
    {
    }

    /** @param SchoolYearData $schoolYear */
    public static function fromSchoolYear(array $schoolYear): self
    {
        $command = new self((int) $schoolYear['id']);
        $command->name = $schoolYear['name'];
        $command->startDate = $schoolYear['startDate'];
        $command->endDate = $schoolYear['endDate'];
        $command->gradingSystem = $schoolYear['gradingSystem'];
        foreach ($schoolYear['gradingPeriods'] as $period) {
            $row = new GradingPeriodInput($period['number']);
            $row->startDate = $period['startDate'];
            $row->endDate = $period['endDate'];
            $command->gradingPeriods[] = $row;
        }

        return $command;
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id] + parent::toLogArray();
    }
}
