<?php

declare(strict_types=1);

namespace App\Tests\Grading\Unit\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Grading\Application\Api\GradingDirectory;
use App\Grading\Application\Service\FailingLearner;
use App\Grading\Application\Service\FailingLearners;
use App\Grading\Application\Service\SectionGradeGrid;
use App\Grading\Application\Service\SheetLearner;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use App\Grading\Domain\Model\Repository\GradeChangeRequestRepositoryInterface;
use App\Schools\Domain\Model\GradingPeriod;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use PHPUnit\Framework\TestCase;

/** The grading API passes each call to the grade services and counts what they return. */
final class GradingDirectoryTest extends TestCase
{
    public function testPassesEachCallOnAndCountsTheFailingLearners(): void
    {
        // Initialize
        $school = new School();
        $schoolYear = new SchoolYear();
        $period = new GradingPeriod($schoolYear, 1);
        $section = new Section($school, $schoolYear);
        $account = new User();
        $learner = new Learner($account, new \DateTimeImmutable());
        $enrollment = new Enrollment($learner, $schoolYear, $section, $account, new \DateTimeImmutable());
        $sheetLearner = new SheetLearner($enrollment, 'Cruz, Lea', false, '100000000001', false, null);
        $failingLearners = [
            new FailingLearner($section, $sheetLearner, 72.0, ['Mathematics' => 72]),
            new FailingLearner($section, $sheetLearner, 70.0, ['Science' => 70]),
        ];
        $grid = self::createStub(SectionGradeGrid::class);
        $grid->method('defaultPeriod')->willReturnMap([[$school, $schoolYear, $period]]);
        $failing = self::createStub(FailingLearners::class);
        $failing->method('forSchool')->willReturnMap([[$school, $schoolYear, $period, $failingLearners]]);
        $requests = self::createStub(GradeChangeRequestRepositoryInterface::class);
        $requests->method('countPendingForSchool')->willReturnMap([[$school, $schoolYear, 4]]);
        $directory = new GradingDirectory($grid, $failing, $requests);

        // Execute
        $default = $directory->defaultPeriod($school, $schoolYear);
        $failingCount = $directory->failingCount($school, $schoolYear, $period);
        $pending = $directory->pendingChangeRequests($school, $schoolYear);

        // Assert
        self::assertTrue($period === $default);
        self::assertSame(2, $failingCount);
        self::assertSame(4, $pending);
    }
}
