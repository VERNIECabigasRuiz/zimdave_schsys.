<?php

declare(strict_types=1);

namespace App\Schools\Presentation\Validation;

use App\Schools\Application\UseCase\Command\GradingPeriodInput;
use App\Schools\Application\UseCase\Command\UpdateSchoolYearCommand;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidGradingPeriodsValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidGradingPeriods) {
            throw new UnexpectedTypeException($constraint, ValidGradingPeriods::class);
        }
        // Leftover periods of the previous system are rebuilt on save, so their dates don't matter
        if (
            !$value instanceof UpdateSchoolYearCommand
            || \count($value->gradingPeriods) !== $value->gradingSystem->periodCount()
        ) {
            return;
        }

        $previous = null;
        foreach (array_values($value->gradingPeriods) as $index => $period) {
            $label = $this->label($value, $period);
            $start = $period->startDate;
            $end = $period->endDate;
            $path = \sprintf('gradingPeriods[%d].', $index);

            if (null === $start || null === $end) {
                $this->addViolation(\sprintf('Enter the dates of %s.', $label), $path . 'startDate');
            } elseif ($end <= $start) {
                $this->addViolation(\sprintf('%s must end after it starts.', $label), $path . 'endDate');
            } elseif ($this->isOutsideTheYear($value, $start, $end)) {
                $this->addViolation(\sprintf('%s must be within the school year.', $label), $path . 'startDate');
            } elseif (null !== $previous?->endDate && $start <= $previous->endDate) {
                $message = \sprintf('%s must start after %s ends.', $label, $this->label($value, $previous));
                $this->addViolation($message, $path . 'startDate');
            }

            $previous = $period;
        }
    }

    /** "Term 2", under the chosen grading system. */
    private function label(UpdateSchoolYearCommand $command, GradingPeriodInput $period): string
    {
        return $command->gradingSystem->periodName() . ' ' . $period->number;
    }

    private function isOutsideTheYear(
        UpdateSchoolYearCommand $command,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
    ): bool {
        return null !== $command->startDate && null !== $command->endDate
            && ($start < $command->startDate || $end > $command->endDate);
    }

    private function addViolation(string $message, string $path): void
    {
        $this->context->buildViolation($message)->atPath($path)->addViolation();
    }
}
