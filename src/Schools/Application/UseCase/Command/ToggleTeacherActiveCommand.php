<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

/** A principal or admin deactivates a teacher, or reactivates them within the plan. */
final readonly class ToggleTeacherActiveCommand extends TeacherChangeCommand
{
}
