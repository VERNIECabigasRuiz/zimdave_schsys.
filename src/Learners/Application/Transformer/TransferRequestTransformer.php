<?php

declare(strict_types=1);

namespace App\Learners\Application\Transformer;

use App\Learners\Domain\Model\TransferRequest;
use App\Learners\Domain\Model\TransferStatus;

/**
 * A transfer request as a learner's page and the transfers page list it.
 *
 * @phpstan-type TransferData array{id: ?int, learner: array{lrn: string, user: array{fullName: string}},
 *     fromSchool: array{name: string}, fromSection: array{label: string}, toSchool: array{name: string},
 *     toSection: array{label: string}, status: TransferStatus, pending: bool, createdAt: \DateTimeImmutable}
 */
final class TransferRequestTransformer
{
    /** @return TransferData */
    public static function toArray(TransferRequest $transfer): array
    {
        $learner = $transfer->getLearner();

        return [
            'id' => $transfer->getId(),
            'learner' => ['lrn' => $learner->getLrn(), 'user' => ['fullName' => $learner->getUser()->getFullName()]],
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
