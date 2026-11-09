<?php

declare(strict_types=1);

namespace App\Grading\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The adviser saves their class's card slip comments for a term; $suggested is each learner's suggestions on the page. */
final readonly class SaveCardSlipCommentsCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $periodId,
        /** @var array<int, array{codes: list<string>, text: ?string}> by enrollment id */
        public array $entries,
        /** @var array<int, list<string>> by enrollment id */
        public array $suggested,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'periodId' => $this->periodId,
            'entries' => \count($this->entries),
            'suggested' => \count($this->suggested),
        ];
    }
}
