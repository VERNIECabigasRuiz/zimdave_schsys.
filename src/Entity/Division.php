<?php

namespace App\Entity;

use App\Repository\DivisionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A schools division and its Superintendent, who signs principals' IDs. Schools pick their region and division from
 * the same fixed list, so a school's division is the entry with the same pair.
 */
#[ORM\Entity(repositoryClass: DivisionRepository::class)]
#[ORM\UniqueConstraint(name: 'uniq_division_region_name', columns: ['region', 'name'])]
#[UniqueEntity(fields: ['region', 'name'], message: 'This division is already on the list.', errorPath: 'name')]
class Division
{
    public const SDS = 'Schools Division Superintendent';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(message: 'Choose a region.')]
    private string $region = '';

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(message: 'Choose a division.')]
    private string $name = '';

    #[ORM\Column(length: 150, nullable: true)]
    #[Assert\NotBlank(message: 'Enter the Superintendent\'s name.')]
    #[Assert\Length(max: 150)]
    private ?string $sdsName = null;

    #[ORM\Column(length: 100)]
    #[Assert\Length(max: 100)]
    private string $sdsPosition = self::SDS;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $signaturePath = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function setRegion(?string $region): static
    {
        $this->region = trim((string) $region);

        return $this;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = trim((string) $name);

        return $this;
    }

    public function getSdsName(): ?string
    {
        return $this->sdsName;
    }

    public function setSdsName(?string $sdsName): static
    {
        $sdsName = null === $sdsName ? null : trim($sdsName);
        $this->sdsName = '' === $sdsName ? null : $sdsName;

        return $this;
    }

    public function getSdsPosition(): string
    {
        return $this->sdsPosition;
    }

    /** Blank means the usual title. */
    public function setSdsPosition(?string $sdsPosition): static
    {
        $sdsPosition = trim((string) $sdsPosition);
        $this->sdsPosition = '' === $sdsPosition ? self::SDS : $sdsPosition;

        return $this;
    }

    public function getSignaturePath(): ?string
    {
        return $this->signaturePath;
    }

    public function setSignaturePath(?string $signaturePath): static
    {
        $this->signaturePath = $signaturePath;

        return $this;
    }
}
