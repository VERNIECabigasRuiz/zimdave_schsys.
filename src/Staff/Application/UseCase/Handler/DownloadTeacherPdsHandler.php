<?php

declare(strict_types=1);

namespace App\Staff\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Application\UseCase\Command\DownloadTeacherPdsCommand;
use App\Staff\Domain\Service\Pds\PdsPdfInterface;

/** @implements UseCaseHandlerInterface<DownloadTeacherPdsCommand> */
#[AsUseCaseHandler(DownloadTeacherPdsCommand::class)]
final class DownloadTeacherPdsHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly PdsPdfInterface $pdf,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param DownloadTeacherPdsCommand $command */
    public function __invoke(UseCase $command): Result
    {
        $this->scope->account($command->actorId);
        $teacher = $this->scope->schoolTeacher($command->schoolId, $command->teacherId);
        $sheet = $this->scope->sheetOf($teacher);
        $this->activityLogger->log('Downloaded PDS of ' . $teacher->getUsername());

        return Result::success(['content' => $this->pdf->render($sheet), 'fileName' => $this->pdf->fileName($sheet)]);
    }
}
