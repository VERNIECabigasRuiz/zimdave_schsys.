<?php

declare(strict_types=1);

namespace App\Learners\Presentation\Validation;

use App\Learners\Application\UseCase\Command\LearnerDetails;
use App\Learners\Domain\Model\Repository\LearnerRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueLrnValidator extends ConstraintValidator
{
    public function __construct(private readonly LearnerRepositoryInterface $learners)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueLrn) {
            throw new UnexpectedTypeException($constraint, UniqueLrn::class);
        }
        if (!$value instanceof LearnerDetails || '' === $value->lrn) {
            return;
        }

        $same = $this->learners->findOneByLrn($value->lrn);
        if (null !== $same && $same->getId() !== $value->learnerId) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ lrn }}', $value->lrn)
                ->setParameter('{{ name }}', $same->getFullName())
                ->atPath('lrn')
                ->addViolation();
        }
    }
}
