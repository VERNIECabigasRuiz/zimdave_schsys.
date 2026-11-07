<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Domain;

use App\Billing\Domain\Model\Plan;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolType;
use PHPUnit\Framework\TestCase;

final class SchoolTest extends TestCase
{
    public function testTrimsTextAndKeepsBlanksAsNull(): void
    {
        // Execute
        $school = (new School())
            ->setSchoolId(' 300001 ')
            ->setName('  Alpha NHS ')
            ->setType(SchoolType::JuniorHigh)
            ->setRegion(null)
            ->setDivision(' Mandaue City ')
            ->setDistrict('  ')
            ->setAddress(' Centro ')
            ->setContactNumber(' ')
            ->setEmail(' office@alpha.test ')
            ->setFacebookUrl('')
            ->setSf8CertifierName(' Ana ')
            ->setSf8CertifierPosition(null)
            ->setSrPreparedByName(' Ben ')
            ->setSrPreparedByDesignation(' Clerk ')
            ->setSrCertifiedByName(' ')
            ->setSrCertifiedByDesignation(' Principal ')
            ->setLpCheckedByName(' Cora ')
            ->setLpCheckedByPosition(' Master Teacher ');

        // Assert
        self::assertNull($school->getId());
        self::assertSame('300001', $school->getSchoolId());
        self::assertSame('Alpha NHS', $school->getName());
        self::assertSame(SchoolType::JuniorHigh, $school->getType());
        self::assertSame('', $school->getRegion());
        self::assertSame('Mandaue City', $school->getDivision());
        self::assertNull($school->getDistrict());
        self::assertSame('Centro', $school->getAddress());
        self::assertNull($school->getContactNumber());
        self::assertSame('office@alpha.test', $school->getEmail());
        self::assertNull($school->getFacebookUrl());
        self::assertSame('Ana', $school->getSf8CertifierName());
        self::assertNull($school->getSf8CertifierPosition());
        self::assertSame('Ben', $school->getSrPreparedByName());
        self::assertSame('Clerk', $school->getSrPreparedByDesignation());
        self::assertNull($school->getSrCertifiedByName());
        self::assertSame('Principal', $school->getSrCertifiedByDesignation());
        self::assertSame('Cora', $school->getLpCheckedByName());
        self::assertSame('Master Teacher', $school->getLpCheckedByPosition());
    }

    public function testLimitsAreThePlanNumbersTimesTheSeats(): void
    {
        // Initialize
        $plan = (new Plan())->setName('Individual')->setTeachersPerSeat(1)->setStudentsPerSeat(45);

        // Execute
        $school = (new School())->setPlan($plan)->setSeats(2);

        // Assert
        self::assertSame($plan, $school->getPlan());
        self::assertSame(2, $school->getSeats());
        self::assertSame(2, $school->getTeacherLimit());
        self::assertSame(90, $school->getStudentLimit());
        self::assertSame('Individual × 2', $school->getPlanLabel());
    }

    public function testPlanLabelWithOneSeatOrNoPlan(): void
    {
        // Initialize
        $plan = (new Plan())->setName('Basic');

        // Execute
        $withPlan = (new School())->setPlan($plan);
        $withoutPlan = new School();

        // Assert
        self::assertSame('Basic', $withPlan->getPlanLabel());
        self::assertSame('—', $withoutPlan->getPlanLabel());
        self::assertSame(0, $withoutPlan->getTeacherLimit());
    }

    public function testPlanIsStillValidOnItsLastDay(): void
    {
        // Initialize
        $school = (new School())->setPlanValidUntil(new \DateTimeImmutable('2026-06-30'));

        // Execute
        $lastDay = $school->isPlanExpired(new \DateTimeImmutable('2026-06-30 23:00'));
        $dayAfter = $school->isPlanExpired(new \DateTimeImmutable('2026-07-01'));
        $never = (new School())->isPlanExpired(new \DateTimeImmutable('2099-01-01'));

        // Assert
        self::assertFalse($lastDay);
        self::assertTrue($dayAfter);
        self::assertNull((new School())->getPlanValidUntil());
        self::assertFalse($never);
    }

    public function testHoldsActiveStateAndUploads(): void
    {
        // Execute
        $school = (new School())
            ->setActive(false)
            ->setLogoPath('uploads/logos/a.webp')
            ->setIdSignaturePath('uploads/signatures/b.webp')
            ->setIdSignatureBy(null);

        // Assert
        self::assertFalse($school->isActive());
        self::assertSame('uploads/logos/a.webp', $school->getLogoPath());
        self::assertSame('uploads/signatures/b.webp', $school->getIdSignaturePath());
        self::assertNull($school->getIdSignatureBy());
    }
}
