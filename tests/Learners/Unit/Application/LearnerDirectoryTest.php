<?php

declare(strict_types=1);

namespace App\Tests\Learners\Unit\Application;

use App\Access\Domain\Model\User;
use App\Classes\Domain\Model\Section;
use App\Learners\Application\Api\LearnerDirectory;
use App\Learners\Domain\Model\Enrollment;
use App\Learners\Domain\Model\Learner;
use App\Learners\Domain\Model\Repository\EnrollmentRepositoryInterface;
use App\Learners\Domain\Model\Repository\LearnerRepositoryInterface;
use App\Learners\Domain\Model\Repository\TransferRequestRepositoryInterface;
use App\Learners\Domain\Service\Sf1\Sf1Remarks;
use App\Schools\Domain\Model\School;
use App\Schools\Domain\Model\SchoolYear;
use PHPUnit\Framework\TestCase;

/** The learner API passes each call to the learner repositories and the SF1 remarks. */
final class LearnerDirectoryTest extends TestCase
{
    public function testPassesEachCallOn(): void
    {
        // Initialize
        $account = (new User())->setUsername('lea');
        $learner = (new Learner($account, new \DateTimeImmutable()))->setLrn('100000000001');
        $school = new School();
        $schoolYear = new SchoolYear();
        $section = new Section($school, $schoolYear);
        $enrollment = new Enrollment($learner, $schoolYear, $section, $account, new \DateTimeImmutable());
        $learners = self::createStub(LearnerRepositoryInterface::class);
        $learners->method('findOneByUser')->willReturnMap([[$account, $learner]]);
        $enrollments = self::createStub(EnrollmentRepositoryInterface::class);
        $enrollments->method('findCurrent')->willReturnMap([[$learner, $schoolYear, $enrollment]]);
        $transfers = $this->createMock(TransferRequestRepositoryInterface::class);
        $transfers->expects(self::exactly(2))->method('countPendingOutgoing')
            ->willReturnMap([[$school, $schoolYear, null, 3], [$school, $schoolYear, $section, 1]]);
        $remarks = self::createStub(Sf1Remarks::class);
        $remarks->method('textFor')->willReturnMap([[$enrollment, 'T/I 2026-08-01']]);
        $directory = new LearnerDirectory($learners, $enrollments, $transfers, $remarks);

        // Execute
        $foundLearner = $directory->learnerOf($account);
        $foundEnrollment = $directory->currentEnrollment($learner, $schoolYear);
        $text = $directory->remarksText($enrollment);
        $schoolWide = $directory->pendingTransfersOut($school, $schoolYear);
        $fromSection = $directory->pendingTransfersOut($school, $schoolYear, $section);

        // Assert
        self::assertSame('100000000001', $foundLearner?->getLrn());
        self::assertTrue($enrollment === $foundEnrollment);
        self::assertSame('T/I 2026-08-01', $text);
        self::assertSame(3, $schoolWide);
        self::assertSame(1, $fromSection);
    }
}
