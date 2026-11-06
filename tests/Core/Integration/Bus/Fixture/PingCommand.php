<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus\Fixture;

use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class PingCommand implements UseCase
{
    public function __construct(
        #[Assert\NotBlank]
        public string $label,
    ) {
    }

    public function toLogArray(): array
    {
        return ['label' => $this->label];
    }
}
