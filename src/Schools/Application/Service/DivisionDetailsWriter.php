<?php

declare(strict_types=1);

namespace App\Schools\Application\Service;

use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use App\Schools\Application\UseCase\Command\DivisionDetails;
use App\Schools\Domain\Exception\DivisionTakenException;
use App\Schools\Domain\Model\Division;
use App\Schools\Domain\Model\Repository\DivisionRepositoryInterface;

/** Saves the division form onto a division: the pair must be new, and a sent signature is stored. */
final class DivisionDetailsWriter
{
    public function __construct(
        private readonly DivisionRepositoryInterface $divisions,
        private readonly ImageProcessorInterface $images,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /**
     * @param string $logLine e.g. "Added division %s."
     *
     * @return array{name: string}
     */
    public function write(Division $division, DivisionDetails $details, string $logLine): array
    {
        $owner = $this->divisions->findByRegionAndName(trim($details->region), trim($details->name));
        if (null !== $owner && $owner !== $division) {
            throw new DivisionTakenException();
        }

        if (null !== $details->signatureFile) {
            $division->setSignaturePath($this->images->storeSignature(
                $details->signatureFile,
                $division->getSignaturePath(),
            ));
        }
        $division->setRegion($details->region)
            ->setName($details->name)
            ->setSdsName($details->sdsName)
            ->setSdsPosition($details->sdsPosition);
        $this->divisions->add($division);
        $this->activityLogger->log(\sprintf($logLine, $division->getName()));

        return ['name' => $division->getName()];
    }
}
