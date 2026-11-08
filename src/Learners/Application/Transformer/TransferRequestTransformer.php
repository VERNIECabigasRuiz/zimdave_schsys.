<?php

declare(strict_types=1);

namespace App\Learners\Application\Transformer;

use App\Learners\Domain\Model\TransferRequest;
use App\Learners\Domain\Model\TransferStatus;

/**
 * A transfer request as a learner's page lists it.
 *
 * @phpstan-type TransferData array{id: ?int, fromSchool: array{name: string}, fromSection: array{label: string},
 *     toSchool: array{name: string}, toSection: array{label: string}, status: TransferStatus, pending: bool,
 *     createdAt: \DateTimeImmutable}
 */
final class TransferRequestTransformer
{
    /** @return TransferData */
    public static function toArray(TransferRequest $transfer): array
    {
        return [
            'id' => $transfer->getId(),
            'fromSchool' => ['name' => $transfer->getFromSchool()->getName()],
            'fromSection' => ['label' => $transfer->getFromSection()->getLabel()],
            'toSchool' => ['name' => $transfer->getToSchool()->getName()],
            'toSection' => ['label' => $transfer->getToSection()->getLabel()],
            'status' => $transfer->getStatus(),
            'pending' => $transfer->isPending(),
            'createdAt' => $transfer->getCreatedAt(),
        ];
    }
}
