<?php

declare(strict_types=1);

namespace App\Grading\Application\Query\Handler;

use App\Classes\Domain\Model\Section;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Grading\Application\Query\Command\GetCardSlipsCommand;
use App\Grading\Application\Service\CardSlipBuilder;
use App\Grading\Application\Service\CardSlipComments;
use App\Grading\Application\Service\GradePolicy;
use App\Grading\Application\Service\GradingScope;
use App\Grading\Application\Service\SectionGradeGrid;
use App\Grading\Domain\Model\Repository\GradeLockRepositoryInterface;
use App\Grading\Domain\Service\CardSlip\CardSlip;
use App\Grading\Domain\Service\CardSlip\CardSlipLine;
use App\Schools\Domain\Model\GradingPeriod;

/** @implements QueryHandlerInterface<GetCardSlipsCommand> */
#[AsQueryHandler(GetCardSlipsCommand::class)]
final class GetCardSlipsHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly GradingScope $scope,
        private readonly GradePolicy $policy,
        private readonly CardSlipBuilder $builder,
        private readonly GradeLockRepositoryInterface $locks,
        private readonly SectionGradeGrid $grid,
    ) {
    }

    /** @param GetCardSlipsCommand $command */
    public function __invoke(Query $command): Result
    {
        $actor = $this->scope->account($command->actorId);
        $section = $this->scope->advisory($actor);
        $this->policy->deny($actor, GradePolicy::CARD_SLIP_EDIT, $section);
        $selected = null === $command->periodId
            ? $this->defaultPeriod($section)
            : $this->scope->sectionPeriod($section, $command->periodId);
        $slips = null === $selected ? [] : $this->builder->forSection($section, $selected);

        return Result::success([
            'section' => $section,
            'periods' => $section->getSchoolYear()->getGradingPeriods(),
            'period' => $selected,
            'locked' => null !== $selected && null !== $this->locks->findFor($section->getSchool(), $selected),
            'slips' => $slips,
            'rows' => array_map(
                static fn (CardSlip $slip) => ['slip' => $slip, 'labels' => self::labels($slip)],
                $slips,
            ),
            'groups' => CardSlipComments::GROUPS,
        ]);
    }

    /** The latest locked term, else what the grade grid opens on (the open term, else today's); null without terms. */
    private function defaultPeriod(Section $section): ?GradingPeriod
    {
        $periods = $section->getSchoolYear()->getGradingPeriods()->getValues();
        usort($periods, static fn (GradingPeriod $a, GradingPeriod $b) => $a->getNumber() <=> $b->getNumber());
        $locked = array_filter(
            $periods,
            fn (GradingPeriod $p) => null !== $this->locks->findFor($section->getSchool(), $p),
        );
        if ([] !== $locked) {
            return end($locked);
        }

        return $this->grid->defaultPeriod($section->getSchool(), $section->getSchoolYear());
    }

    /**
     * @return array<string, string> checkbox label by code; "needs improvement" names the learner's failing subjects,
     *                               and is left out (so its checkbox is not shown) when the learner fails none
     */
    private static function labels(CardSlip $slip): array
    {
        $failing = array_values(array_map(
            static fn (CardSlipLine $line) => $line->label,
            array_filter($slip->lines, static fn (CardSlipLine $line) => null !== $line->grade && $line->grade < 75),
        ));
        $labels = [];
        foreach (CardSlipComments::GROUPS as $codes) {
            foreach ($codes as $code) {
                if ('needs_improvement' === $code && [] === $failing) {
                    continue;
                }
                $labels[$code] = CardSlipComments::text($code, $failing);
            }
        }

        return $labels;
    }
}
