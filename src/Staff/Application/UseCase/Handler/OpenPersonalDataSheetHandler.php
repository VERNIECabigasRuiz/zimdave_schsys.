<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Application\UseCase\Command\OpenPersonalDataSheetCommand;
use App\Staff\Domain\Model\PersonalDataSheet;
use App\Staff\Domain\Model\Repository\PersonalDataSheetRepositoryInterface;

/** @implements UseCaseHandlerInterface<OpenPersonalDataSheetCommand> */
#[AsUseCaseHandler(OpenPersonalDataSheetCommand::class)]
final class OpenPersonalDataSheetHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly PersonalDataSheetRepositoryInterface $sheets,
    ) {
    }

    /** @param OpenPersonalDataSheetCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        if (null === $this->sheets->findFor($actor)) {
            $this->sheets->add(new PersonalDataSheet($actor));
        }

        return Result::success(null);
    }
}
