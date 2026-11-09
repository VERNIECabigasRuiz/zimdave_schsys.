<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Handler;

use App\Classes\Application\Service\ClassPeriodManager;
use App\Classes\Application\Service\Timetable\TimetableManager;
use App\Classes\Application\Service\Timetable\TimetableScope;
use App\Classes\Application\UseCase\Command\SetTeachingLoadCommand;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\Lock\TransactionLockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<SetTeachingLoadCommand> */
#[AsUseCaseHandler(SetTeachingLoadCommand::class)]
final class SetTeachingLoadHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly TransactionLockInterface $locks,
        private readonly TimetableScope $scope,
        private readonly TimetableManager $manager,
    ) {
    }

    /** @param SetTeachingLoadCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->locks->acquire(ClassPeriodManager::LOCK_PREFIX . $command->schoolId, ClassPeriodManager::BUSY);
        $section = $this->scope->section($command->schoolId, $command->sectionId);
        $subject = $this->scope->subject($section, $command->subjectId);
        $teacher = null === $command->teacherId ? null : $this->scope->teacher($section, $command->teacherId);
        $this->manager->setTeacher($section, $subject, $teacher);

        return Result::success();
    }
}
