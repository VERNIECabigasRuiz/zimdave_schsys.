<?php

declare(strict_types=1);

namespace App\Schools\Application\Transformer;

use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolType;

/**
 * @phpstan-type SchoolData array{
 *     id: int, schoolId: string, name: string, type: ?SchoolType, region: string, division: string,
 *     district: ?string, address: ?string, active: bool, planId: ?int, planName: ?string, planLabel: string,
 *     includesPrincipal: bool, seats: ?int, teacherLimit: int, planValidUntil: ?\DateTimeImmutable,
 *     planExpired: bool, logoPath: ?string, idSignaturePath: ?string, idSignatureById: ?int,
 *     contactNumber: ?string, email: ?string, facebookUrl: ?string, sf8CertifierName: ?string,
 *     sf8CertifierPosition: ?string, srPreparedByName: ?string, srPreparedByDesignation: ?string,
 *     srCertifiedByName: ?string, srCertifiedByDesignation: ?string, lpCheckedByName: ?string,
 *     lpCheckedByPosition: ?string
 * }
 */
final class SchoolTransformer
{
    /** @return SchoolData */
    public static function toArray(School $school, \DateTimeImmutable $today): array
    {
        $plan = $school->getPlan();

        return [
            'id' => (int) $school->getId(),
            'schoolId' => $school->getSchoolId(),
            'name' => $school->getName(),
            'type' => $school->getType(),
            'region' => $school->getRegion(),
            'division' => $school->getDivision(),
            'district' => $school->getDistrict(),
            'address' => $school->getAddress(),
            'active' => $school->isActive(),
            'planId' => $plan?->getId(),
            'planName' => $plan?->getName(),
            'planLabel' => $school->getPlanLabel(),
            'includesPrincipal' => false !== $plan?->includesPrincipal(),
            'seats' => $school->getSeats(),
            'teacherLimit' => $school->getTeacherLimit(),
            'planValidUntil' => $school->getPlanValidUntil(),
            'planExpired' => $school->isPlanExpired($today),
            'logoPath' => $school->getLogoPath(),
            'idSignaturePath' => $school->getIdSignaturePath(),
            'idSignatureById' => $school->getIdSignatureBy()?->getId(),
            'contactNumber' => $school->getContactNumber(),
            'email' => $school->getEmail(),
            'facebookUrl' => $school->getFacebookUrl(),
            'sf8CertifierName' => $school->getSf8CertifierName(),
            'sf8CertifierPosition' => $school->getSf8CertifierPosition(),
            'srPreparedByName' => $school->getSrPreparedByName(),
            'srPreparedByDesignation' => $school->getSrPreparedByDesignation(),
            'srCertifiedByName' => $school->getSrCertifiedByName(),
            'srCertifiedByDesignation' => $school->getSrCertifiedByDesignation(),
            'lpCheckedByName' => $school->getLpCheckedByName(),
            'lpCheckedByPosition' => $school->getLpCheckedByPosition(),
        ];
    }
}
