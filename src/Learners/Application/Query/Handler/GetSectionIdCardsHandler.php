<?php

declare(strict_types=1);

namespace App\Learners\Application\Query\Handler;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Learners\Application\Query\Command\GetSectionIdCardsCommand;
use App\Learners\Application\Service\IdCardBuilder;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Application\Service\LearnersScope;
use App\Learners\Domain\Service\LearnerId\IdCardFileName;
use App\Learners\Domain\Service\LearnerId\IdCardPdfInterface;
use App\Learners\Domain\Service\LearnerId\IdLayout;

/** @implements QueryHandlerInterface<GetSectionIdCardsCommand> */
#[AsQueryHandler(GetSectionIdCardsCommand::class)]
final class GetSectionIdCardsHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly LearnersScope $scope,
        private readonly LearnerPolicy $policy,
        private readonly IdCardBuilder $cards,
        private readonly IdCardPdfInterface $pdf,
    ) {
    }

    /** @param GetSectionIdCardsCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->section(
            $this->scope->page($command->schoolId, $command->fixedSectionId),
            $command->sectionId,
        );
        $this->policy->deny($actor, LearnerPolicy::VIEW_CLASS, $section);
        $layout = IdLayout::tryFrom($command->layout) ?? IdLayout::Sheet;

        return Result::success([
            'fileName' => IdCardFileName::forSection($section),
            'content' => $this->pdf->render($this->cards->forSection($section), $layout),
        ]);
    }
}
