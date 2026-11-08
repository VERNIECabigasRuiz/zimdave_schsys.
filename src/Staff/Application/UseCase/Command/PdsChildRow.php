<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Staff\Domain\Model\PdsChild;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Validator\Constraints as Assert;

/** One child on the Family Background tab. */
final class PdsChildRow
{
    #[Assert\NotBlank(message: 'Enter the child\'s full name.', groups: ['Default', PersonalDataSheet::FAMILY])]
    #[Assert\Length(max: 150, groups: ['Default', PersonalDataSheet::FAMILY])]
    public ?string $fullName = null;

    #[Assert\LessThanOrEqual(
        'today',
        message: 'Date of birth cannot be in the future.',
        groups: ['Default', PersonalDataSheet::FAMILY],
    )]
    public ?\DateTimeImmutable $dateOfBirth = null;

    public static function fromEntity(PdsChild $entity): self
    {
        $row = new self();
        $row->fullName = $entity->fullName;
        $row->dateOfBirth = $entity->dateOfBirth;

        return $row;
    }

    public function applyTo(PdsChild $entity): void
    {
        $entity->fullName = $this->fullName;
        $entity->dateOfBirth = $this->dateOfBirth;
    }
}
