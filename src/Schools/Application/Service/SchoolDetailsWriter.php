<?php

declare(strict_types=1);

namespace App\Schools\Application\Service;

use App\Billing\Application\Api\PlanCatalogInterface;
use App\Schools\Application\UseCase\Command\SchoolDetails;
use App\Schools\Domain\Exception\SchoolIdTakenException;
use App\Schools\Domain\Model\Repository\SchoolRepositoryInterface;
use App\Schools\Domain\Model\School;

/** Copies the school form's fields onto a school, refusing a School ID another school has. */
final class SchoolDetailsWriter
{
    public function __construct(
        private readonly SchoolRepositoryInterface $schools,
        private readonly PlanCatalogInterface $plans,
    ) {
    }

    public function write(School $school, SchoolDetails $details): void
    {
        $owner = $this->schools->findBySchoolId(trim($details->schoolId));
        if (null !== $owner && $owner !== $school) {
            throw new SchoolIdTakenException();
        }
        $plan = $this->plans->findById((int) $details->planId)
            ?? throw new \InvalidArgumentException('Unknown plan.');

        $school->setSchoolId($details->schoolId)
            ->setName($details->name)
            ->setType($details->type)
            ->setRegion($details->region)
            ->setDivision($details->division)
            ->setDistrict($details->district)
            ->setAddress($details->address)
            ->setPlan($plan)
            ->setSeats($details->seats)
            ->setPlanValidUntil($details->planValidUntil);
    }

    /** "303456 Alpha NHS", for the activity log. */
    public static function describe(School $school): string
    {
        return $school->getSchoolId() . ' ' . $school->getName();
    }
}
