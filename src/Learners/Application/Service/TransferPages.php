<?php

declare(strict_types=1);

namespace App\Learners\Application\Service;

use App\Access\Domain\Model\User;
use App\Classes\Application\Api\SectionDirectoryInterface;
use App\Classes\Domain\Model\Section;
use App\Learners\Domain\Exception\LearnersAccessDeniedException;
use App\Learners\Domain\Exception\TransferNotFoundException;
use App\Learners\Domain\Model\Repository\TransferRequestRepositoryInterface;
use App\Learners\Domain\Model\TransferRequest;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;

/**
 * Who may open a school's transfers page and which requests it reaches. Admins and the principal see the
 * whole school; on a plan without a principal the adviser of one of its sections sees that section.
 */
class TransferPages
{
    public function __construct(
        private readonly LearnerPolicy $policy,
        private readonly SectionDirectoryInterface $sections,
        private readonly TransferRequestRepositoryInterface $requests,
    ) {
    }

    /**
     * Null for the whole school, else the adviser's section.
     *
     * @throws LearnersAccessDeniedException when $user may not open the page
     */
    public function adviserOf(School $school, User $user, ?SchoolYear $schoolYear): ?Section
    {
        if ($this->policy->managesSchool($user, $school)) {
            return null;
        }

        $advisory = null !== $schoolYear && false === $school->getPlan()?->includesPrincipal()
            ? $this->sections->advisoryOf($user, $schoolYear)
            : null;
        if (null === $advisory || $advisory->getSchool()->getId() !== $school->getId()) {
            throw new LearnersAccessDeniedException();
        }

        return $advisory;
    }

    /**
     * A request of the current school year leaving $school ($outgoing) or coming into it.
     *
     * @throws TransferNotFoundException
     */
    public function find(School $school, ?SchoolYear $schoolYear, int $transferId, bool $outgoing): TransferRequest
    {
        $request = $this->requests->findById($transferId);
        $side = $outgoing ? $request?->getFromSchool() : $request?->getToSchool();
        if (
            null === $request
            || null === $schoolYear
            || $request->getFromSection()->getSchoolYear()->getId() !== $schoolYear->getId()
            || $side?->getId() !== $school->getId()
        ) {
            throw new TransferNotFoundException();
        }

        return $request;
    }
}
