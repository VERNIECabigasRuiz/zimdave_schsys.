<?php

declare(strict_types=1);

namespace App\Classes\Application\Query\Handler;

use App\Classes\Application\Query\Command\GetSubjectScopeCommand;
use App\Classes\Application\Service\Subject\SubjectScopeResolver;
use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<GetSubjectScopeCommand> */
#[AsQueryHandler(GetSubjectScopeCommand::class)]
final class GetSubjectScopeHandler implements QueryHandlerInterface
{
    public function __construct(private readonly SubjectScopeResolver $resolver)
    {
    }

    /** @param GetSubjectScopeCommand $command */
    public function __invoke(Query $command): Result
    {
        $scope = $this->resolver->resolve($command->scope);

        return Result::success(['schoolId' => (int) $scope->school->getId()]);
    }
}
