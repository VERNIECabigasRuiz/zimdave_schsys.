<?php

namespace App\Validator;

use App\Entity\ServiceRecordEntry;
use App\Repository\ServiceRecordEntryRepository;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Compares in PHP against the employee's other rows as loaded, so a row changed in memory but not yet flushed
 * counts with its new dates. An open row runs to the present, so it overlaps every later row.
 */
final class ValidServiceRowValidator extends ConstraintValidator
{
    public function __construct(private readonly ServiceRecordEntryRepository $entries)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidServiceRow) {
            throw new UnexpectedTypeException($constraint, ValidServiceRow::class);
        }
        if (!$value instanceof ServiceRecordEntry || null === $value->getFromDate()) {
            return;
        }

        $from = $value->getFromDate();
        $to = $value->getToDate();
        if (null !== $to && $to < $from) {
            $this->context->buildViolation($constraint->endBeforeStart)->atPath('toDate')->addViolation();

            return;
        }
        $lwopFrom = $value->getLwopFrom();
        $lwopTo = $value->getLwopTo();
        if ((null === $lwopFrom) !== (null === $lwopTo) || (null !== $lwopFrom && $lwopTo < $lwopFrom)) {
            $this->context->buildViolation($constraint->lwop)->atPath('lwopTo')->addViolation();
        }

        foreach ($this->entries->findForEmployee($value->getEmployee()) as $other) {
            if ($other === $value || (null !== $value->getId() && $other->getId() === $value->getId())) {
                continue;
            }
            $otherTo = $other->getToDate();
            // the open row this row will close on saving ends the day before it
            if (null === $otherTo && $value->closeOpen && $other->getFromDate() < $from) {
                $otherTo = $from->modify('-1 day');
            }
            if (null === $otherTo && null === $to && !$value->closeOpen) {
                $this->context->buildViolation($constraint->secondOpen)->atPath('toDate')->addViolation();

                return;
            }
            if ((null === $to || $other->getFromDate() <= $to) && (null === $otherTo || $from <= $otherTo)) {
                $this->context->buildViolation($constraint->overlap)
                    ->setParameter('{{ from }}', $other->getFromDate()->format('m/d/Y'))
                    ->atPath('fromDate')
                    ->addViolation();

                return;
            }
        }
    }
}
