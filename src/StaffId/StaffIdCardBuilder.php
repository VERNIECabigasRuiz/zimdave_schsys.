<?php

namespace App\StaffId;

use App\Data\PhilippineDivisions;
use App\Entity\PersonalDataSheet;
use App\Entity\School;
use App\Entity\SexAtBirth;
use App\Entity\User;
use App\Repository\DivisionRepository;
use App\Repository\PersonalDataSheetRepository;
use App\Repository\SchoolAssignmentRepository;
use App\Repository\SchoolYearRepository;
use App\Repository\ServiceRecordEntryRepository;
use App\Repository\StaffIdDetailsRepository;
use App\Core\Domain\Service\FileStorage\ImageProcessorInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gathers a teacher's or principal's ID: name and numbers from the PDS, position from the service record's Present
 * row, and the head who signs — the school's principal for a teacher, the division's Superintendent for a principal.
 */
class StaffIdCardBuilder
{
    public const PRINCIPAL_FALLBACK = 'School Principal';
    private const SEAL = '/assets/images/deped-seal.png';

    public function __construct(
        private readonly PersonalDataSheetRepository $sheets,
        private readonly ServiceRecordEntryRepository $entries,
        private readonly StaffIdDetailsRepository $details,
        private readonly SchoolAssignmentRepository $assignments,
        private readonly SchoolYearRepository $schoolYears,
        private readonly DivisionRepository $divisions,
        private readonly ImageProcessorInterface $images,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    public function build(User $person, School $school): StaffIdCard
    {
        $sheet = $this->sheets->findOneBy(['user' => $person]);
        $details = $this->details->findOrNew($person);
        [$fullName, $surname, $givenNames] = $this->names($person);
        [$headName, $headPosition, $headSignature] = $this->head($person, $school);
        $website = $school->getFacebookUrl();
        $website = null === $website ? $school->getEmail() : preg_replace('#^https?://(www\.)?#i', '', rtrim($website, '/'));
        $seal = $this->projectDir.self::SEAL;

        return new StaffIdCard(
            fullName: $fullName,
            surname: $surname,
            givenNames: $givenNames,
            callName: $details->getCallName() ?? $this->callName($sheet, $person),
            position: $this->entries->findCurrent($person)?->getDesignation(),
            employeeNo: $sheet?->agencyEmployeeNo,
            tin: $sheet?->tin,
            philhealthNo: $sheet?->philhealthNo,
            pagibigNo: $sheet?->pagibigNo,
            bloodType: $sheet?->bloodType,
            gsisPolicyNo: $details->getGsisPolicyNo(),
            emergencyName: $details->getEmergencyName(),
            emergencyContact: $details->getEmergencyContact(),
            region: $school->getRegion(),
            regionLabel: mb_strtoupper(str_replace('–', '-', PhilippineDivisions::label($school->getRegion()))),
            division: $school->getDivision(),
            schoolName: $school->getName(),
            schoolAddress: $school->getAddress(),
            website: $website,
            photo: $this->image($person->getPhotoPath(), jpeg: true),
            logo: $this->image($school->getLogoPath()),
            seal: is_file($seal) ? 'data:image/png;base64,'.base64_encode((string) file_get_contents($seal)) : null,
            headName: $headName,
            headPosition: $headPosition,
            headSignature: $headSignature,
        );
    }

    /** @return list<'photo'|'position'> what stops the ID from printing: a card without either is useless */
    public function missing(User $person): array
    {
        $missing = [];
        if (null === $person->getPhotoPath()) {
            $missing[] = 'photo';
        }
        if (null === $this->entries->findCurrent($person)?->getDesignation()) {
            $missing[] = 'position';
        }

        return $missing;
    }

    public function canPrint(User $person): bool
    {
        return [] === $this->missing($person);
    }

    /** @return array{0: string, 1: string, 2: string} full name, surname, given names; from the PDS, else the account */
    private function names(User $person): array
    {
        $sheet = $this->sheets->findOneBy(['user' => $person]);
        $surname = trim((string) $sheet?->surname);
        if (null === $sheet || '' === $surname) {
            $full = mb_strtoupper(trim($person->getFullName()));
            $words = preg_split('/\s+/', $full) ?: [$full];
            $last = array_pop($words);

            return [$full, $last, [] === $words ? $last : implode(' ', $words)];
        }

        $middle = trim((string) $sheet->middleName);
        $initial = '' === $middle || 'N/A' === mb_strtoupper($middle) ? '' : mb_substr($middle, 0, 1).'.';
        $first = trim($sheet->firstName.' '.$initial);
        $extension = trim((string) $sheet->nameExtension);
        $extension = 'N/A' === mb_strtoupper($extension) ? '' : $extension;

        return array_map(mb_strtoupper(...), [
            trim($first.' '.$surname.' '.$extension),
            $surname,
            trim($first.' '.$extension),
        ]);
    }

    /** "Sir JUAN" / "Ma'am ANA" by the PDS sex; just the first name when it isn't filled in. */
    private function callName(?PersonalDataSheet $sheet, User $person): string
    {
        $first = trim((string) $sheet?->firstName);
        if ('' === $first) {
            $first = explode(' ', trim($person->getFullName()))[0];
        }
        $first = mb_strtoupper($first);

        return match ($sheet?->sexAtBirth) {
            SexAtBirth::Male => 'Sir '.$first,
            SexAtBirth::Female => "Ma'am ".$first,
            default => $first,
        };
    }

    /** @return array{0: ?string, 1: ?string, 2: ?string} name, position, signature of whoever signs this person's ID */
    private function head(User $person, School $school): array
    {
        $schoolYear = $this->schoolYears->findCurrent();
        $assignment = null === $schoolYear ? null : $this->assignments->findForUser($person, $schoolYear);

        if (User::ROLE_PRINCIPAL === $assignment?->getRole()) {
            $division = $this->divisions->findForSchool($school);

            return null === $division
                ? [null, null, null]
                : [null === $division->getSdsName() ? null : mb_strtoupper($division->getSdsName()), $division->getSdsPosition(), $this->image($division->getSignaturePath())];
        }

        $principal = null === $schoolYear ? null : $this->assignments->findPrincipal($school, $schoolYear)?->getUser();
        if (null === $principal) {
            return [null, null, null];
        }

        return [
            mb_strtoupper($principal->getFullName()),
            $this->entries->findCurrent($principal)?->getDesignation() ?? self::PRINCIPAL_FALLBACK,
            // a signature left by an earlier principal must not print under the new one's name
            $school->getIdSignatureBy() === $principal ? $this->image($school->getIdSignaturePath()) : null,
        ];
    }

    /**
     * A stored .webp inlined (Dompdf may not load files): photos as JPEG, which Dompdf embeds as is, signatures and
     * logos as PNG to keep their transparency.
     */
    private function image(?string $path, bool $jpeg = false): ?string
    {
        $file = null === $path ? null : $this->images->absolutePath($path);
        $image = null !== $file && is_file($file) ? @imagecreatefromwebp($file) : false;
        if (!$image instanceof \GdImage) {
            return null;
        }

        ob_start();
        if ($jpeg) {
            imagejpeg($image, null, 90);
        } else {
            imagesavealpha($image, true);
            imagepng($image);
        }

        return 'data:image/'.($jpeg ? 'jpeg' : 'png').';base64,'.base64_encode((string) ob_get_clean());
    }
}
