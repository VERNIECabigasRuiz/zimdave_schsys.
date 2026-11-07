<?php

declare(strict_types=1);

namespace App\Schools\Application\Query\Handler;

use App\Access\Domain\Model\User;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Domain\Service\Clock\SchoolClockInterface;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;
use App\Schools\Application\Query\Command\GetPrincipalAssignmentCommand;
use App\Schools\Application\Service\SchoolYearResolver;
use App\Schools\Application\Transformer\SchoolTransformer;
use App\Schools\Application\Transformer\SchoolYearTransformer;
use App\Schools\Domain\Exception\SchoolNotFoundException;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;

/** @implements QueryHandlerInterface<GetPrincipalAssignmentCommand> */
#[AsQueryHandler(GetPrincipalAssignmentCommand::class)]
final class GetPrincipalAssignmentHandler implements QueryHandlerInterface
{
    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
        private readonly SchoolYearResolver $schoolYears,
        private readonly SchoolClockInterface $clock,
    ) {
    }

    /** @param GetPrincipalAssignmentCommand $command */
    public function __invoke(Query $command): Result
    {
        $school = $this->schools->findById($command->schoolId) ?? throw new SchoolNotFoundException();
        $schoolYear = $this->schoolYears->resolve($command->schoolYearId);
        $current = $this->assignments->findPrincipal($school, $schoolYear)?->getUser();

        return Result::success([
            'school' => SchoolTransformer::toArray($school, $this->clock->now()),
            'schoolYear' => SchoolYearTransformer::toArray($schoolYear),
            'current' => null !== $current ? self::person($current) : null,
            'principals' => array_map(
                static fn (User $user) => self::person($user),
                $this->assignments->findAssignablePrincipals($schoolYear, $current),
            ),
        ]);
    }

    /** @return array{id: int, fullName: string} */
    private static function person(User $user): array
    {
        return ['id' => (int) $user->getId(), 'fullName' => $user->getFullName()];
    }
}
