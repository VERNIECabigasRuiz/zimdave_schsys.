<?php

declare(strict_types=1);

namespace App\Classes\Application\UseCase\Handler;

use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Service\SectionManager;
use App\Classes\Application\UseCase\Command\BringBackSectionsCommand;
use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;

/** @implements UseCaseHandlerInterface<BringBackSectionsCommand> */
#[AsUseCaseHandler(BringBackSectionsCommand::class)]
final class BringBackSectionsHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly ClassesScope $scope,
        private readonly SectionManager $manager,
    ) {
    }

    /** @param BringBackSectionsCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $school = $this->scope->school($command->schoolId);
        $schoolYear = $this->scope->currentYear();
        $previous = $this->scope->previousYear($schoolYear);

        return Result::success([
            'count' => $this->manager->bringBack($school, $previous, $schoolYear),
            'from' => $previous->getName(),
        ]);
    }
}
