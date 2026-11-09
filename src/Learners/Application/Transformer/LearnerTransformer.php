<?php

declare(strict_types=1);

namespace App\Learners\Application\Transformer;

use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\SexAtBirth;

/**
 * A learner as the pages show it; the keys follow the entity's getters, so templates read both alike.
 *
 * @phpstan-type LearnerData array{id: ?int, lrn: string, lastName: string, firstName: string,
 *     middleName: ?string, extension: ?string, fullName: string, sex: ?SexAtBirth, birthDate: ?\DateTimeImmutable,
 *     motherTongue: ?string, ipGroup: ?string, religion: ?string, addressStreet: ?string,
 *     addressBarangay: ?string, addressCity: ?string, addressProvince: ?string, fatherLastName: ?string,
 *     fatherFirstName: ?string, fatherMiddleName: ?string, motherLastName: ?string, motherFirstName: ?string,
 *     motherMiddleName: ?string, guardianName: ?string, guardianRelationship: ?string, contactNumber: ?string,
 *     sf1Name: string, fatherSf1Name: ?string, motherSf1Name: ?string, sf1Incomplete: bool,
 *     user: array{id: ?int, fullName: string, initials: string, photoPath: ?string}}
 */
final class LearnerTransformer
{
    /** @return LearnerData */
    public static function toArray(Learner $learner): array
    {
        $user = $learner->getUser();

        return [
            'id' => $learner->getId(),
            'lrn' => $learner->getLrn(),
            'lastName' => $learner->getLastName(),
            'firstName' => $learner->getFirstName(),
            'middleName' => $learner->getMiddleName(),
            'extension' => $learner->getExtension(),
            'fullName' => $learner->getFullName(),
            'sex' => $learner->getSex(),
            'birthDate' => $learner->getBirthDate(),
            'motherTongue' => $learner->getMotherTongue(),
            'ipGroup' => $learner->getIpGroup(),
            'religion' => $learner->getReligion(),
            'addressStreet' => $learner->getAddressStreet(),
            'addressBarangay' => $learner->getAddressBarangay(),
            'addressCity' => $learner->getAddressCity(),
            'addressProvince' => $learner->getAddressProvince(),
            'fatherLastName' => $learner->getFatherLastName(),
            'fatherFirstName' => $learner->getFatherFirstName(),
            'fatherMiddleName' => $learner->getFatherMiddleName(),
            'motherLastName' => $learner->getMotherLastName(),
            'motherFirstName' => $learner->getMotherFirstName(),
            'motherMiddleName' => $learner->getMotherMiddleName(),
            'guardianName' => $learner->getGuardianName(),
            'guardianRelationship' => $learner->getGuardianRelationship(),
            'contactNumber' => $learner->getContactNumber(),
            'sf1Name' => $learner->getSf1Name(),
            'fatherSf1Name' => $learner->getFatherSf1Name(),
            'motherSf1Name' => $learner->getMotherSf1Name(),
            'sf1Incomplete' => $learner->isSf1Incomplete(),
            'user' => [
                'id' => $user->getId(),
                'fullName' => $user->getFullName(),
                'initials' => $user->getInitials(),
                'photoPath' => $user->getPhotoPath(),
            ],
        ];
    }
}
