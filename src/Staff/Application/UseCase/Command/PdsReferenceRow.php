<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Staff\Domain\Model\PdsReference;
use App\Staff\Domain\Model\PersonalDataSheet;
use Symfony\Component\Validator\Constraints as Assert;

/** One reference on the References tab; a started row needs all three fields. */
final class PdsReferenceRow
{
    #[Assert\Length(max: 150, groups: [PersonalDataSheet::REFERENCES])]
    #[Assert\Expression(
        '!this.isMissing("name")',
        message: 'Enter the name.',
        groups: [PersonalDataSheet::REFERENCES],
    )]
    public ?string $name = null;

    /** Office / residential address. */
    #[Assert\Length(max: 255, groups: [PersonalDataSheet::REFERENCES])]
    #[Assert\Expression(
        '!this.isMissing("address")',
        message: 'Enter the office or residential address.',
        groups: [PersonalDataSheet::REFERENCES],
    )]
    public ?string $address = null;

    /** Contact no. and/or e-mail. */
    #[Assert\Length(max: 100, groups: [PersonalDataSheet::REFERENCES])]
    #[Assert\Expression(
        '!this.isMissing("contact")',
        message: 'Enter a contact number or e-mail.',
        groups: [PersonalDataSheet::REFERENCES],
    )]
    public ?string $contact = null;

    public static function fromEntity(PdsReference $entity): self
    {
        $row = new self();
        $row->name = $entity->name;
        $row->address = $entity->address;
        $row->contact = $entity->contact;

        return $row;
    }

    public function applyTo(PdsReference $entity): void
    {
        $entity->name = $this->name;
        $entity->address = $this->address;
        $entity->contact = $this->contact;
    }

    /** A row left completely blank on the form (it is not saved). */
    public function isEmpty(): bool
    {
        return \in_array($this->name, [null, ''], true)
            && \in_array($this->address, [null, ''], true)
            && \in_array($this->contact, [null, ''], true);
    }

    /** A started row needs all three fields: $field is one of them, left empty. */
    public function isMissing(string $field): bool
    {
        return !$this->isEmpty() && \in_array($this->{$field}, [null, ''], true);
    }
}
