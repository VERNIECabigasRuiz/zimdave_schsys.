<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Infrastructure\Bus\Fixture;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<SampleQuery> */
#[AsQueryHandler(SampleQuery::class)]
final class SampleQueryHandler implements QueryHandlerInterface
{
    public function __invoke(Query $command): Result
    {
        return Result::success();
    }
}
