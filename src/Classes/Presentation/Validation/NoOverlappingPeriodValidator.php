<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Validation;

use App\Classes\Application\UseCase\Command\ClassPeriodDetails;
use App\Classes\Domain\Model\Repository\ClassPeriodRepositoryInterface;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class NoOverlappingPeriodValidator extends ConstraintValidator
{
    public function __construct(
        private readonly ClassPeriodRepositoryInterface $periods,
        private readonly SchoolDirectoryInterface $schools,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NoOverlappingPeriod) {
            throw new UnexpectedTypeException($constraint, NoOverlappingPeriod::class);
        }
        if (!$value instanceof ClassPeriodDetails || null === $value->startsAt || null === $value->endsAt) {
            return;
        }
        $start = $value->startsAt->format('H:i');
        $end = $value->endsAt->format('H:i');
        $school = $this->schools->findSchool($value->schoolId);
        $schoolYear = $this->schools->currentYear();
        if ($end <= $start || null === $school || null === $schoolYear) {
            return;
        }

        $existing = $this->periods->findForSchool($school, $schoolYear);
        $numbers = $this->periods->numbering($existing);
        foreach ($existing as $other) {
            if ($other->getId() === $value->periodId()) {
                continue;
            }
            if ($start < $other->getEndsAt()?->format('H:i') && $other->getStartsAt()?->format('H:i') < $end) {
                $this->context->buildViolation($constraint->message)
                    ->setParameter('{{ label }}', $other->isBreak()
                        ? (string) $other->getBreakLabel()
                        : 'Period ' . $numbers[(int) $other->getId()])
                    ->setParameter('{{ range }}', $other->getTimeRange())
                    ->atPath('startsAt')
                    ->addViolation();

                return;
            }
        }
    }
}
