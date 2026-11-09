<?php

declare(strict_types=1);

namespace App\Dashboards\Presentation\Controller;

use App\Core\Application\Bus\Query;
use App\Core\Application\Bus\QueryBusInterface;
use App\Core\Presentation\Controller\BusController;

/** Dashboard pages read through the bus; their queries never refuse. */
abstract class AbstractDashboardController extends BusController
{
    public function __construct(private readonly QueryBusInterface $queryBus)
    {
    }

    /** @return array<string, mixed> */
    protected function ask(Query $query): array
    {
        $result = $this->queryBus->handle($query);
        $failure = $this->domainFailure($result);
        null === $failure || throw new \LogicException('A dashboard query refused: ' . $failure->getMessage());
        /** @var array<string, mixed> $data */
        $data = $result->data();

        return $data;
    }
}
