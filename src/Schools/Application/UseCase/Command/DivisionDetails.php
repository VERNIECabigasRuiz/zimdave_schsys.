<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use App\Schools\Domain\Model\Division;
use Symfony\Component\Validator\Constraints as Assert;

/** The fields an admin fills in for a division and its Superintendent. */
abstract class DivisionDetails implements UseCase
{
    #[Assert\NotBlank(message: 'Choose a region.')]
    public string $region = '';

    #[Assert\NotBlank(message: 'Choose a division.')]
    public string $name = '';

    #[Assert\NotBlank(message: 'Enter the Superintendent\'s name.')]
    #[Assert\Length(max: 150)]
    public ?string $sdsName = null;

    #[Assert\Length(max: 100)]
    public string $sdsPosition = Division::SDS;

    /** The uploaded signature's temporary file, if one was sent. */
    public ?string $signatureFile = null;

    public function toLogArray(): array
    {
        return ['region' => $this->region, 'name' => $this->name];
    }
}
