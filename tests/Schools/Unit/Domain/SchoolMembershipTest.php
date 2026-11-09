<?php

declare(strict_types=1);

namespace App\Tests\Schools\Unit\Domain;

use App\Access\Domain\Model\User;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolAssignment;
use App\Schools\Domain\Model\SchoolDayOff;
use App\Schools\Domain\Model\SchoolYear;
use PHPUnit\Framework\TestCase;

final class SchoolMembershipTest extends TestCase
{
    public function testAnAssignmentCanMoveToAnotherSchoolAndUser(): void
    {
        // Initialize
        $schoolYear = new SchoolYear();
        $teacher = new User();
        $assignment = new SchoolAssignment(new School(), $schoolYear, $teacher, User::ROLE_TEACHER);
        $otherSchool = new School();
        $otherTeacher = new User();

        // Execute
        $assignment->setSchool($otherSchool)->setUser($otherTeacher);

        // Assert
        self::assertNull($assignment->getId());
        self::assertSame($otherSchool, $assignment->getSchool());
        self::assertSame($otherTeacher, $assignment->getUser());
        self::assertSame($schoolYear, $assignment->getSchoolYear());
        self::assertSame(User::ROLE_TEACHER, $assignment->getRole());
        self::assertLessThanOrEqual(new \DateTimeImmutable(), $assignment->getCreatedAt());
    }

    public function testADayOffKeepsTheDateWithoutTimeAndTheTrimmedReason(): void
    {
        // Initialize
        $school = new School();
        $schoolYear = new SchoolYear();
        $principal = new User();
        $createdAt = new \DateTimeImmutable('2025-08-20 09:30');

        // Execute
        $dayOff = new SchoolDayOff(
            $school,
            $schoolYear,
            new \DateTimeImmutable('2025-08-21 14:00'),
            ' Ninoy Aquino Day ',
            $principal,
            $createdAt,
        );

        // Assert
        self::assertNull($dayOff->getId());
        self::assertSame($school, $dayOff->getSchool());
        self::assertSame($schoolYear, $dayOff->getSchoolYear());
        self::assertSame('2025-08-21 00:00', $dayOff->getDate()->format('Y-m-d H:i'));
        self::assertSame('Ninoy Aquino Day', $dayOff->getReason());
        self::assertSame($principal, $dayOff->getCreatedBy());
        self::assertSame($createdAt, $dayOff->getCreatedAt());
    }
}
