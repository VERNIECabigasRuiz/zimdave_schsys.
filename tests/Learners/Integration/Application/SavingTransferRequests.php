<?php

declare(strict_types=1);

namespace App\Tests\Learners\Integration\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Learners\Application\Service\TransferRequests;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\TransferRequest;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Calls the transfer requests service as a use case would: a decision first finds the pending request, and
 * every call that succeeds is saved.
 */
final class SavingTransferRequests
{
    public function __construct(
        private readonly TransferRequests $service,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function request(Enrollment $from, Section $to, User $by): TransferRequest
    {
        $request = $this->service->request($from, $to, $by);
        $this->em->flush();

        return $request;
    }

    public function approve(TransferRequest $request, User $by): void
    {
        $this->service->approve($this->pending($request), $by);
        $this->em->flush();
    }

    public function decline(TransferRequest $request, User $by): void
    {
        $this->service->decline($this->pending($request), $by);
        $this->em->flush();
    }

    public function cancel(TransferRequest $request, User $by): void
    {
        $this->service->cancel($this->pending($request), $by);
        $this->em->flush();
    }

    private function pending(TransferRequest $request): TransferRequest
    {
        return $this->service->pending((int) $request->getId(), (int) $request->getLearner()->getId());
    }
}
