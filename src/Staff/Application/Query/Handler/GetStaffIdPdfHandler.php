<?php

declare(strict_types=1);

namespace App\Staff\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Staff\Application\Query\Command\GetStaffIdPdfCommand;
use App\Staff\Application\Service\StaffIdCardBuilder;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Domain\Exception\StaffIdNotPrintableException;
use App\Staff\Domain\Service\StaffId\StaffIdFileName;
use App\Staff\Domain\Service\StaffId\StaffIdPdfInterface;

/** @implements QueryHandlerInterface<GetStaffIdPdfCommand> */
#[AsQueryHandler(GetStaffIdPdfCommand::class)]
final class GetStaffIdPdfHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly StaffIdCardBuilder $builder,
        private readonly StaffIdPdfInterface $pdf,
    ) {
    }

    /** @param GetStaffIdPdfCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $school = $this->scope->idHolder($actor)->getSchool();
        if (!$this->builder->canPrint($actor)) {
            throw new StaffIdNotPrintableException();
        }
        $card = $this->builder->build($actor, $school);

        return Result::success([
            'content' => $this->pdf->render($card, $command->size),
            'fileName' => StaffIdFileName::forCard($card, $command->size),
        ]);
    }
}
