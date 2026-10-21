<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/** PDS item 41: a reference not related by consanguinity or affinity. The form has three. */
#[ORM\Entity]
class PdsReference
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'references')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?PersonalDataSheet $sheet = null;

    #[ORM\Column]
    public int $position = 0;

    #[ORM\Column(length: 150)]
    #[Assert\Length(max: 150, groups: [PersonalDataSheet::REFERENCES])]
    public ?string $name = null;

    /** Office / residential address. */
    #[ORM\Column(length: 255)]
    #[Assert\Length(max: 255, groups: [PersonalDataSheet::REFERENCES])]
    public ?string $address = null;

    /** Contact no. and/or e-mail. */
    #[ORM\Column(length: 100)]
    #[Assert\Length(max: 100, groups: [PersonalDataSheet::REFERENCES])]
    public ?string $contact = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    /** A row left completely blank on the form (it is not saved). */
    public function isEmpty(): bool
    {
        return \in_array($this->name, [null, ''], true)
            && \in_array($this->address, [null, ''], true)
            && \in_array($this->contact, [null, ''], true);
    }

    /** A started row needs all three fields. */
    #[Assert\Callback(groups: [PersonalDataSheet::REFERENCES])]
    public function validateComplete(ExecutionContextInterface $context): void
    {
        if ($this->isEmpty()) {
            return;
        }
        $messages = ['name' => 'Enter the name.', 'address' => 'Enter the office or residential address.', 'contact' => 'Enter a contact number or e-mail.'];
        foreach ($messages as $field => $message) {
            if (\in_array($this->{$field}, [null, ''], true)) {
                $context->buildViolation($message)->atPath($field)->addViolation();
            }
        }
    }
}
