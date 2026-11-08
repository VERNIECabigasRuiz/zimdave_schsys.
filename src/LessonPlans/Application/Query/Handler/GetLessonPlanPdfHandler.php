<?php

declare(strict_types=1);

namespace App\LessonPlans\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\LessonPlans\Application\Query\Command\GetLessonPlanPdfCommand;
use App\LessonPlans\Application\Service\LessonPlanPolicy;
use App\LessonPlans\Application\Service\LessonPlanPrinter;
use App\LessonPlans\Application\Service\LessonPlanScope;
use App\LessonPlans\Domain\Service\Pdf\LessonPlanPdfInterface;

/** @implements QueryHandlerInterface<GetLessonPlanPdfCommand> */
#[AsQueryHandler(GetLessonPlanPdfCommand::class)]
final class GetLessonPlanPdfHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LessonPlanScope $scope,
        private readonly LessonPlanPrinter $printer,
        private readonly LessonPlanPdfInterface $pdf,
    ) {
    }

    /** @param GetLessonPlanPdfCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $printout = $this->printer->printout($this->scope->plan($actor, $command->planId, LessonPlanPolicy::VIEW));

        return Result::success(['content' => $this->pdf->render($printout), 'fileName' => $printout->fileName()]);
    }
}
