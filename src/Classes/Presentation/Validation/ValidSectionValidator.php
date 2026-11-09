<?php

declare(strict_types=1);

namespace App\Classes\Presentation\Validation;

use App\Classes\Application\UseCase\Command\SectionDetails;
use App\Classes\Domain\Model\Section;
use App\Schools\Application\Api\SchoolDirectoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class ValidSectionValidator extends ConstraintValidator
{
    public function __construct(private readonly SchoolDirectoryInterface $schools)
    {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidSection) {
            throw new UnexpectedTypeException($constraint, ValidSection::class);
        }
        if (!$value instanceof SectionDetails || null === $value->gradeLevel) {
            return;
        }

        $type = $this->schools->findSchool($value->schoolId)?->getType();
        if (null !== $type && !\in_array($value->gradeLevel, $type->gradeLevels(), true)) {
            $this->context->buildViolation(\sprintf(
                '%s is not offered by %s.',
                $value->gradeLevel->label(),
                $type->withArticle(),
            ))->atPath('gradeLevel')->addViolation();
        }
        if ($value->gradeLevel->isSeniorHigh() && null === $value->track) {
            $this->context->buildViolation($constraint->seniorHighTrackMessage)->atPath('track')->addViolation();
        }
        $strand = Section::normalizeStrand($value->strand);
        if (!$value->gradeLevel->isSeniorHigh() && (null !== $value->track || null !== $strand)) {
            $this->context->buildViolation($constraint->trackOnlyForSeniorHighMessage)
                ->atPath('track')
                ->addViolation();
        }
    }
}
