<?php

declare(strict_types=1);

namespace App\Learners\Domain\Model\Repository;

use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\TransferRequest;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

interface TransferRequestRepositoryInterface
{
    public function findPending(Learner $learner, SchoolYear $schoolYear): ?TransferRequest;

    public function findApprovedInto(Enrollment $enrollment): ?TransferRequest;

    /** @return array<int, TransferRequest> keyed by enrollment id */
    public function findApprovedIntoSection(Section $section): array;

    /** @return list<TransferRequest> */
    public function findApprovedOutOf(Section $section, \DateTimeImmutable $since): array;

    /** @return list<TransferRequest> */
    public function findIncoming(School $school, SchoolYear $schoolYear): array;

    /** @return list<TransferRequest> */
    public function findOutgoing(School $school, SchoolYear $schoolYear, ?Section $fromSection = null): array;

    public function countPendingOutgoing(School $school, SchoolYear $schoolYear, ?Section $fromSection = null): int;

    public function countPendingInto(Section $section): int;

    /** @return list<TransferRequest> newest first */
    public function findForLearner(Learner $learner): array;

    public function findById(int $id): ?TransferRequest;

    /** The request as it is saved now, even when an older copy was loaded earlier in the same request. */
    public function findFresh(int $id): ?TransferRequest;

    public function add(TransferRequest $request): void;
}
