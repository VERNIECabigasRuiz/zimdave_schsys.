<?php

declare(strict_types=1);

namespace App\Tests\Core\Unit\Presentation\Controller\Fixture;

use App\Core\Application\Bus\Result;
use App\Core\Domain\Exception\DomainException;
use App\Core\Presentation\Controller\BusController;

/** Makes domainFailure() callable from a test. */
final class ExposedBusController extends BusController
{
    public function failureOf(Result $result): ?DomainException
    {
        return $this->domainFailure($result);
    }
}
