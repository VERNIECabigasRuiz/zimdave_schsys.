<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

/**
 * The school edits a period of its day. Bound to the class period form, prefilled from GetClassPeriodFormCommand.
 *
 * @phpstan-import-type ClassPeriodData from \App\Classes\Application\Transformer\ClassPeriodTransformer
 */
final class UpdateClassPeriodCommand extends ClassPeriodDetails
{
    public function __construct(int $schoolId, public readonly int $id)
    {
        parent::__construct($schoolId);
    }

    /** @param ClassPeriodData $period */
    public static function fromPeriod(int $schoolId, array $period): self
    {
        $command = new self($schoolId, (int) $period['id']);
        $command->startsAt = $period['startsAt'];
        $command->endsAt = $period['endsAt'];
        $command->isBreak = $period['break'];
        $command->breakLabel = $period['breakLabel'];

        return $command;
    }

    public function periodId(): int
    {
        return $this->id;
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id] + parent::toLogArray();
    }
}
