<?php

declare(strict_types=1);

namespace App\Schools\Application\UseCase\Command;

use App\Core\Application\Bus\UseCase;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A principal edits their school's profile (the fields they may change). Bound to the school profile form,
 * prefilled from GetSchoolProfileCommand.
 *
 * @phpstan-import-type SchoolData from \App\Schools\Application\Transformer\SchoolTransformer
 */
final class UpdateSchoolProfileCommand implements UseCase
{
    #[Assert\Length(max: 255)]
    public ?string $address = null;

    #[Assert\Length(max: 30)]
    public ?string $contactNumber = null;

    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\Url(requireTld: true)]
    #[Assert\Length(max: 255)]
    public ?string $facebookUrl = null;

    #[Assert\Length(max: 100)]
    public ?string $sf8CertifierName = null;

    #[Assert\Length(max: 100)]
    public ?string $sf8CertifierPosition = null;

    #[Assert\Length(max: 100)]
    public ?string $srPreparedByName = null;

    #[Assert\Length(max: 100)]
    public ?string $srPreparedByDesignation = null;

    #[Assert\Length(max: 100)]
    public ?string $srCertifiedByName = null;

    #[Assert\Length(max: 100)]
    public ?string $srCertifiedByDesignation = null;

    #[Assert\Length(max: 100)]
    public ?string $lpCheckedByName = null;

    #[Assert\Length(max: 100)]
    public ?string $lpCheckedByPosition = null;

    /** The uploaded logo's temporary file, if one was sent. */
    public ?string $logoFile = null;

    /** The uploaded ID signature's temporary file, if one was sent. */
    public ?string $idSignatureFile = null;

    public function __construct(public readonly int $userId)
    {
    }

    /** @param SchoolData $school */
    public static function fromSchool(int $userId, array $school): self
    {
        $command = new self($userId);
        $command->address = $school['address'];
        $command->contactNumber = $school['contactNumber'];
        $command->email = $school['email'];
        $command->facebookUrl = $school['facebookUrl'];
        $command->sf8CertifierName = $school['sf8CertifierName'];
        $command->sf8CertifierPosition = $school['sf8CertifierPosition'];
        $command->srPreparedByName = $school['srPreparedByName'];
        $command->srPreparedByDesignation = $school['srPreparedByDesignation'];
        $command->srCertifiedByName = $school['srCertifiedByName'];
        $command->srCertifiedByDesignation = $school['srCertifiedByDesignation'];
        $command->lpCheckedByName = $school['lpCheckedByName'];
        $command->lpCheckedByPosition = $school['lpCheckedByPosition'];

        return $command;
    }

    public function toLogArray(): array
    {
        return [
            'userId' => $this->userId,
            'logo' => null !== $this->logoFile,
            'idSignature' => null !== $this->idSignatureFile,
        ];
    }
}
