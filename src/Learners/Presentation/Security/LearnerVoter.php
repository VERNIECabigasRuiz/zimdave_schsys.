<?php

declare(strict_types=1);

namespace App\Learners\Presentation\Security;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Learners\Application\Service\LearnerPolicy;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\TransferRequest;
use App\Schools\Domain\Model\School;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * The learner permissions for pages and templates; the rules are LearnerPolicy's.
 *
 * @extends Voter<string, mixed>
 */
final class LearnerVoter extends Voter
{
    public const ENROLL = LearnerPolicy::ENROLL;
    public const VIEW_CLASS = LearnerPolicy::VIEW_CLASS;
    public const EDIT = LearnerPolicy::EDIT;
    public const RESET_PASSWORD = LearnerPolicy::RESET_PASSWORD;
    public const UNENROLL = LearnerPolicy::UNENROLL;
    public const CHANGE_LRN = LearnerPolicy::CHANGE_LRN;
    public const MOVE = LearnerPolicy::MOVE;
    public const REQUEST_TRANSFER = LearnerPolicy::REQUEST_TRANSFER;
    public const DECIDE_TRANSFER = LearnerPolicy::DECIDE_TRANSFER;
    public const CANCEL_TRANSFER = LearnerPolicy::CANCEL_TRANSFER;

    public function __construct(private readonly LearnerPolicy $policy)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->policy->supports($attribute, $subject);
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();
        // supports() lets only these through
        \assert(
            $subject instanceof Section
            || $subject instanceof Learner
            || $subject instanceof School
            || $subject instanceof TransferRequest,
        );

        return $user instanceof User && $this->policy->can($user, $attribute, $subject);
    }
}
