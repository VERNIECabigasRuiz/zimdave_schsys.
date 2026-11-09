<?php

declare(strict_types=1);

namespace App\Tests\Grading\Integration\Application;

use App\Grading\Application\Service\GradeChangeManager;
use App\Grading\Application\Service\GradeRecorder;
use Doctrine\ORM\EntityManagerInterface;

/** For kernel tests: the grade recorder and change request manager from the container, saving as a use case would. */
trait SavesGrades
{
    private function savingRecorder(): SavingGradeRecorder
    {
        return new SavingGradeRecorder(
            static::getContainer()->get(GradeRecorder::class),
            static::getContainer()->get(EntityManagerInterface::class),
        );
    }

    private function savingChangeManager(): SavingGradeChangeManager
    {
        return new SavingGradeChangeManager(
            static::getContainer()->get(GradeChangeManager::class),
            static::getContainer()->get(EntityManagerInterface::class),
        );
    }
}
