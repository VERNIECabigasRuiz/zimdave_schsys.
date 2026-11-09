<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Security;

use App\Access\Domain\Model\User;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use App\Schools\Domain\Model\Repository\SchoolAssignmentRepositoryInterface;
use App\Schools\Domain\Model\Repository\SchoolYearRepositoryInterface;
use App\Schools\Domain\Model\School;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may manage a school's people: administrators always, a principal only their
 * own school in the current school year.
 *
 * @extends Voter<string, School>
 */
final class SchoolVoter extends Voter
{
    public const MANAGE = SchoolDirectoryInterface::MANAGE;

    public function __construct(
        private readonly SchoolYearRepositoryInterface $schoolYears,
        private readonly SchoolAssignmentRepositoryInterface $assignments,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return self::MANAGE === $attribute && $subject instanceof School;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (\in_array(User::ROLE_ADMIN, $user->getRoles(), true)) {
            return true;
        }

        if (User::ROLE_PRINCIPAL !== $user->getRole() || null === $currentYear = $this->schoolYears->findCurrent()) {
            return false;
        }

        return $this->assignments->findSchoolOf(
            $user,
            $currentYear,
            User::ROLE_PRINCIPAL,
        )?->getId() === $subject->getId();
    }
}
