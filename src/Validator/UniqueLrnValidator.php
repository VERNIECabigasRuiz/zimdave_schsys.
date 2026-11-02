<?php

namespace App\Validator;

use App\Entity\Learner;
use App\Repository\LearnerRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class UniqueLrnValidator extends ConstraintValidator
{
    public function __construct(private readonly LearnerRepository $learners)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof UniqueLrn) {
            throw new UnexpectedTypeException($constraint, UniqueLrn::class);
        }
        if (!$value instanceof Learner || '' === $value->getLrn()) {
            return;
        }

        $same = $this->learners->findOneByLrn($value->getLrn());
        if (null !== $same && $same !== $value) {
            $this->context->buildViolation($constraint->message)
                ->setParameter('{{ lrn }}', $value->getLrn())
                ->setParameter('{{ name }}', $same->getFullName())
                ->atPath('lrn')
                ->addViolation();
        }
    }
}
