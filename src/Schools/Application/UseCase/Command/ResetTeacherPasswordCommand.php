<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

/** A principal or admin gives a teacher a new temporary password. */
final readonly class ResetTeacherPasswordCommand extends TeacherChangeCommand
{
}
