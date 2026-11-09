<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Handler;

use App\Core\Application\Bus\Result;
use App\Core\Application\Bus\UseCase;
use App\Core\Application\Bus\UseCaseHandlerInterface;
use App\Core\Domain\Service\ActivityLog\ActivityLoggerInterface;
use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use App\Core\Infrastructure\Bus\Attributes\AsUseCaseHandler;
use App\Schools\Application\Service\PrincipalSchoolFinder;
use App\Schools\Application\UseCase\Command\UpdateSchoolProfileCommand;
use App\Schools\Domain\Exception\SchoolNotFoundException;

/** @implements UseCaseHandlerInterface<UpdateSchoolProfileCommand> */
#[AsUseCaseHandler(UpdateSchoolProfileCommand::class)]
final class UpdateSchoolProfileHandler implements UseCaseHandlerInterface
{
    public function __construct(
        private readonly PrincipalSchoolFinder $finder,
        private readonly ImageProcessorInterface $images,
        private readonly ActivityLoggerInterface $activityLogger,
    ) {
    }

    /** @param UpdateSchoolProfileCommand $command */
    public function __invoke(UseCase $command): Result
    {
        [$user, , $school] = $this->finder->find($command->userId);
        if (null === $user || null === $school) {
            throw new SchoolNotFoundException();
        }

        $school->setAddress($command->address)
            ->setContactNumber($command->contactNumber)
            ->setEmail($command->email)
            ->setFacebookUrl($command->facebookUrl)
            ->setSf8CertifierName($command->sf8CertifierName)
            ->setSf8CertifierPosition($command->sf8CertifierPosition)
            ->setSrPreparedByName($command->srPreparedByName)
            ->setSrPreparedByDesignation($command->srPreparedByDesignation)
            ->setSrCertifiedByName($command->srCertifiedByName)
            ->setSrCertifiedByDesignation($command->srCertifiedByDesignation)
            ->setLpCheckedByName($command->lpCheckedByName)
            ->setLpCheckedByPosition($command->lpCheckedByPosition);
        if (null !== $command->logoFile) {
            $school->setLogoPath($this->images->storeLogo($command->logoFile, $school->getLogoPath()));
        }
        if (null !== $command->idSignatureFile) {
            $school->setIdSignaturePath($this->images->storeSignature(
                $command->idSignatureFile,
                $school->getIdSignaturePath(),
            ))->setIdSignatureBy($user);
        }
        $this->activityLogger->log('Updated school profile ' . $school->getName());

        return Result::success(['name' => $school->getName()]);
    }
}
