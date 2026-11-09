<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Validator\Constraints as Assert;

/** The Other Information tab (31–33) as the person saves it. */
final class SaveOtherInfoCommand implements UseCase
{
    public int $actorId = 0;

    /**
     * 31. Special skills and hobbies.
     *
     * @var array<int, string> keyed as on the form
     */
    public array $skills = [];

    /**
     * 32. Non-academic distinctions / recognition.
     *
     * @var array<int, string> keyed as on the form
     */
    public array $distinctions = [];

    /**
     * 33. Membership in association / organization.
     *
     * @var array<int, string> keyed as on the form
     */
    public array $memberships = [];

    public static function fromSheet(PersonalDataSheet $sheet, int $actorId): self
    {
        $command = new self();
        $command->actorId = $actorId;
        $command->skills = $sheet->skills;
        $command->distinctions = $sheet->distinctions;
        $command->memberships = $sheet->memberships;

        return $command;
    }

    /** Writes this tab's fields onto the sheet. */
    public function applyTo(PersonalDataSheet $sheet): void
    {
        $sheet->skills = array_values($this->skills);
        $sheet->distinctions = array_values($this->distinctions);
        $sheet->memberships = array_values($this->memberships);
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'tab' => 'other',
            'skills' => \count($this->skills),
            'distinctions' => \count($this->distinctions),
            'memberships' => \count($this->memberships),
        ];
    }
}
