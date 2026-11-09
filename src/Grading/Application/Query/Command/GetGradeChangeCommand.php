<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Command;

use App\Core\Application\Bus\Query;

/** One change request (of the school, when given) that the account may act on as $attribute says. */
final readonly class GetGradeChangeCommand implements Query
{
    public function __construct(
        public int $actorId,
        public ?int $schoolId,
        public int $requestId,
        public string $attribute,
    ) {
    }

    public function toLogArray(): array
    {
        return [
            'actorId' => $this->actorId,
            'schoolId' => $this->schoolId,
            'requestId' => $this->requestId,
            'attribute' => $this->attribute,
        ];
    }
}
