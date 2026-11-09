<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

/**
 * An admin edits a division. Bound to the division form, prefilled from GetDivisionCommand.
 *
 * @phpstan-import-type DivisionData from \App\Schools\Application\Transformer\DivisionTransformer
 */
final class UpdateDivisionCommand extends DivisionDetails
{
    public function __construct(public readonly int $id)
    {
    }

    /** @param DivisionData $division */
    public static function fromDivision(array $division): self
    {
        $command = new self($division['id']);
        $command->region = $division['region'];
        $command->name = $division['name'];
        $command->sdsName = $division['sdsName'];
        $command->sdsPosition = $division['sdsPosition'];

        return $command;
    }

    public function toLogArray(): array
    {
        return ['id' => $this->id] + parent::toLogArray();
    }
}
