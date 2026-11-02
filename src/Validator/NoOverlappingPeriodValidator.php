<?php

namespace App\Validator;

use App\Entity\ClassPeriod;
use App\Repository\ClassPeriodRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class NoOverlappingPeriodValidator extends ConstraintValidator
{
    public function __construct(private readonly ClassPeriodRepository $periods)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoOverlappingPeriod) {
            throw new UnexpectedTypeException($constraint, NoOverlappingPeriod::class);
        }
        if (!$value instanceof ClassPeriod || null === $value->getStartsAt() || null === $value->getEndsAt()) {
            return;
        }

        $start = $value->getStartsAt()->format('H:i');
        $end = $value->getEndsAt()->format('H:i');
        if ($end <= $start) {
            return;
        }

        $existing = $this->periods->findForSchool($value->getSchool(), $value->getSchoolYear());
        $numbers = $this->periods->numbering($existing);
        foreach ($existing as $other) {
            if ($other === $value || (null !== $value->getId() && $other->getId() === $value->getId())) {
                continue;
            }
            if ($start < $other->getEndsAt()->format('H:i') && $other->getStartsAt()->format('H:i') < $end) {
                $this->context->buildViolation($constraint->message)
                    ->setParameter('{{ label }}', $other->isBreak() ? (string) $other->getBreakLabel() : 'Period '.$numbers[$other->getId()])
                    ->setParameter('{{ range }}', $other->getTimeRange())
                    ->atPath('startsAt')
                    ->addViolation();

                return;
            }
        }
    }
}
