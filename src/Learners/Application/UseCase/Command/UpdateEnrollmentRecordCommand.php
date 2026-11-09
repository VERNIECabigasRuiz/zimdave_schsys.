<?php

declare(strict_types=1);

namespace App\Learners\Application\UseCase\Command;

use App\Learners\Domain\Model\LearningModality;
use App\Learners\Domain\Model\Sf1Remark;
use Symfony\Component\Validator\Constraints as Assert;

/** Saves this school year's SF1 record of a learner: learning modality and remarks. Bound to the record form. */
final class UpdateEnrollmentRecordCommand extends LearnerChange
{
    public LearningModality $learningModality = LearningModality::FaceToFace;

    // details or a date mean nothing without the remark they belong to
    #[Assert\Expression(
        'value !== null or (this.remarkDetails === null and this.remarkDate === null)',
        message: 'Choose a remark for these details.',
    )]
    public ?Sf1Remark $remarkCode = null;

    #[Assert\Length(max: 150)]
    public ?string $remarkDetails = null;

    public ?\DateTimeImmutable $remarkDate = null;

    public function toLogArray(): array
    {
        return parent::toLogArray() + [
            'learningModality' => $this->learningModality->value,
            'remarkCode' => $this->remarkCode?->value,
        ];
    }
}
