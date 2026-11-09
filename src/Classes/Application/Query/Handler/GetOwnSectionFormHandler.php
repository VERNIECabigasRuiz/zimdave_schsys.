<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Handler;

use App\Classes\Application\Query\Command\GetOwnSectionFormCommand;
use App\Classes\Application\Service\ClassesScope;
use App\Classes\Application\Service\OwnSectionGate;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Domain\Model\GradeLevel;

/** @implements QueryHandlerInterface<GetOwnSectionFormCommand> */
#[AsQueryHandler(GetOwnSectionFormCommand::class)]
final class GetOwnSectionFormHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly ClassesScope $scope,
        private readonly OwnSectionGate $gate,
    ) {
    }

    /** @param GetOwnSectionFormCommand $command */
    public function __invoke(Query $command): Result
    {
        $own = $this->gate->check($this->scope->account($command->teacherId));
        if (null === $own) {
            return Result::success(['school' => null, 'grades' => []]);
        }
        [$school] = $own;

        return Result::success([
            'school' => ['id' => (int) $school->getId(), 'name' => $school->getName()],
            'grades' => $school->getType()?->gradeLevels() ?? GradeLevel::cases(),
        ]);
    }
}
