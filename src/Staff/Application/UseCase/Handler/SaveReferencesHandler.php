<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Staff\Application\Service\PdsSaver;
use App\Staff\Application\UseCase\Command\SaveReferencesCommand;
use App\Staff\Domain\Model\PersonalDataSheet;

/** @implements UseCaseHandlerInterface<SaveReferencesCommand> */
#[AsUseCaseHandler(SaveReferencesCommand::class)]
final class SaveReferencesHandler implements UseCaseHandlerInterface
{
    public function __construct(private readonly PdsSaver $saver)
    {
    }

    /** @param SaveReferencesCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->saver->save($command->actorId, static function (PersonalDataSheet $sheet) use ($command): void {
            $command->applyTo($sheet);
            $sheet->removeEmptyReferences();
            $sheet->renumberLists();
        });

        return Result::success(null);
    }
}
