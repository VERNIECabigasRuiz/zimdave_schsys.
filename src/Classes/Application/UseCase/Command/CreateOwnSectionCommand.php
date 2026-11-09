<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Command;

/**
 * On a plan without a principal, a teacher creates the section they advise.
 * Bound to the section form without the adviser field.
 */
final class CreateOwnSectionCommand extends SectionDetails
{
    public function __construct(int $schoolId, public readonly int $teacherId)
    {
        parent::__construct($schoolId);
        $this->adviserId = $teacherId;
    }

    public function toLogArray(): array
    {
        return ['teacherId' => $this->teacherId] + parent::toLogArray();
    }
}
