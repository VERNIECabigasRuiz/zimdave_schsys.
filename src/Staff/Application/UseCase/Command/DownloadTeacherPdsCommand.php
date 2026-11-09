<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;

/** The principal or an administrator downloads the PDS of a teacher of the school; the result is the PDF. */
final readonly class DownloadTeacherPdsCommand implements UseCase
{
    public function __construct(
        public int $actorId,
        public int $schoolId,
        public int $teacherId,
    ) {
    }

    public function toLogArray(): array
    {
        return ['actorId' => $this->actorId, 'schoolId' => $this->schoolId, 'teacherId' => $this->teacherId];
    }
}
