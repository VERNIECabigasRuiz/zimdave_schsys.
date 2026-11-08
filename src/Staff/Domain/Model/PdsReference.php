<?php

declare(strict_types=1);

namespace App\Staff\Domain\Model;

use Doctrine\ORM\Mapping as ORM;

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
    public ?string $name = null;

    /** Office / residential address. */
    #[ORM\Column(length: 255)]
    public ?string $address = null;

    /** Contact no. and/or e-mail. */
    #[ORM\Column(length: 100)]
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
}
