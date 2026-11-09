<?php

declare(strict_types=1);

namespace App\Core\Presentation\Controller;

use App\Core\Application\Bus\Result;
use App\Core\Domain\Exception\DomainException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

/**
 * A controller that sends its work through the bus.
 */
abstract class BusController extends AbstractController
{
    /**
     * The refusal to show the user, or null when $result succeeded. Any other failure is thrown
     * again, so it ends in an error page as it would without the bus.
     */
    protected function domainFailure(Result $result): ?DomainException
    {
        $exception = $result->exception();
        if ($result->isSuccess() || null === $exception) {
            return null;
        }
        if ($exception instanceof DomainException) {
            return $exception;
        }

        throw $exception;
    }
}
