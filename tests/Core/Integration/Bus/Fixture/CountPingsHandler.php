<?php

declare(strict_types=1);

namespace App\Tests\Core\Integration\Bus\Fixture;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryHandlerInterface;
use App\Core\Application\Bus\Result;
use App\Core\Infrastructure\Bus\Attributes\AsQueryHandler;

/** @implements QueryHandlerInterface<CountPingsQuery> */
#[AsQueryHandler(CountPingsQuery::class)]
final class CountPingsHandler implements QueryHandlerInterface
{
    public static int $calls = 0;

    public function __invoke(Query $command): Result
    {
        return Result::success(++self::$calls);
    }
}
