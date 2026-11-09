<?php

declare(strict_types=1);

namespace App\Staff\Presentation\Validation;

use App\Access\Domain\Exception\AccountNotFoundException;
use App\Staff\Application\Service\StaffScope;
use App\Staff\Application\UseCase\Command\SaveServiceRowCommand;
use App\Staff\Domain\Model\Repository\ServiceRecordEntryRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Compares the row with the employee's other saved rows; the row being edited is left out. An open row runs to
 * the present, so it overlaps every later row.
 */
final class ValidServiceRowValidator extends ConstraintValidator
{
    public function __construct(
        private readonly StaffScope $scope,
        private readonly ServiceRecordEntryRepositoryInterface $entries,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidServiceRow) {
            throw new UnexpectedTypeException($constraint, ValidServiceRow::class);
        }
        if (!$value instanceof SaveServiceRowCommand || null === $value->fromDate) {
            return;
        }

        $from = $value->fromDate->setTime(0, 0);
        $to = $value->toDate?->setTime(0, 0);
        if (null !== $to && $to < $from) {
            $this->context->buildViolation($constraint->endBeforeStart)->atPath('toDate')->addViolation();

            return;
        }
        $lwopFrom = $value->lwopFrom?->setTime(0, 0);
        $lwopTo = $value->lwopTo?->setTime(0, 0);
        if ((null === $lwopFrom) !== (null === $lwopTo) || (null !== $lwopFrom && $lwopTo < $lwopFrom)) {
            $this->context->buildViolation($constraint->lwop)->atPath('lwopTo')->addViolation();
        }

        try {
            $employee = $this->scope->account($value->employeeId);
        } catch (AccountNotFoundException) {
            return;
        }
        foreach ($this->entries->findForEmployee($employee) as $other) {
            $otherFrom = $other->getFromDate();
            if ($other->getId() === $value->entryId || null === $otherFrom) {
                continue;
            }
            $otherTo = $other->getToDate();
            // the open row this row will close on saving ends the day before it
            if (null === $otherTo && $value->closeOpen && $otherFrom < $from) {
                $otherTo = $from->modify('-1 day');
            }
            if (null === $otherTo && null === $to && !$value->closeOpen) {
                $this->context->buildViolation($constraint->secondOpen)->atPath('toDate')->addViolation();

                return;
            }
            if ((null === $to || $otherFrom <= $to) && (null === $otherTo || $from <= $otherTo)) {
                $this->context->buildViolation($constraint->overlap)
                    ->setParameter('{{ from }}', $otherFrom->format('m/d/Y'))
                    ->atPath('fromDate')
                    ->addViolation();

                return;
            }
        }
    }
}
